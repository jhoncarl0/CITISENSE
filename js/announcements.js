/* ==========================================================================
   CitiSense Admin — Announcements
   --------------------------------------------------------------------------
   1. Picture picker (add / preview / remove before saving, drag and drop)
   2. Edit form (fills in text and existing pictures)
   3. Picture viewer (lightbox with previous / next)
   ========================================================================== */

(function () {
    "use strict";

    const CS = window.CitiSense;
    const MAX_BYTES = 5 * 1024 * 1024;
    const TYPES = ["image/jpeg", "image/png", "image/webp"];

    /* ------------------------------------------------------------------ */
    /* 1. Picture picker                                                   */
    /* ------------------------------------------------------------------ */

    function setupPicker(picker) {
        const input = picker.querySelector("[data-photo-input]");
        const list = picker.querySelector("[data-photo-list]");
        const errorBox = picker.querySelector("[data-photo-error]");
        const drop = picker.querySelector(".ann-picker-drop");
        const form = picker.closest("form");
        const max = Number(picker.dataset.max) || 6;

        let files = [];        // new File objects chosen in this session
        let existing = [];     // { id, url, removed } already saved

        function showError(message) {
            errorBox.textContent = message || "";
            errorBox.hidden = !message;
        }

        function keptCount() {
            return existing.filter((img) => !img.removed).length + files.length;
        }

        function syncInput() {
            const transfer = new DataTransfer();
            files.forEach((file) => transfer.items.add(file));
            input.files = transfer.files;
        }

        function tile(src, label, onRemove, removed) {
            const item = document.createElement("div");
            item.className = "ann-picker-item" + (removed ? " is-removed" : "");

            const img = document.createElement("img");
            img.src = src;
            img.alt = "";

            const button = document.createElement("button");
            button.type = "button";
            button.className = "ann-picker-remove";
            button.setAttribute("aria-label", removed ? "Keep " + label : "Remove " + label);
            button.innerHTML = removed
                ? '<i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i><span>Undo</span>'
                : '<i class="bi bi-x-lg" aria-hidden="true"></i><span>Remove</span>';
            button.addEventListener("click", onRemove);

            item.append(img, button);

            if (removed) {
                const note = document.createElement("span");
                note.className = "ann-picker-note";
                note.textContent = "Will be removed";
                item.append(note);
            }

            return item;
        }

        function render() {
            list.replaceChildren();

            existing.forEach((img, index) => {
                list.append(tile(img.url, "picture " + (index + 1), () => {
                    if (img.removed && keptCount() >= max) {
                        showError("You can keep at most " + max + " pictures.");
                        return;
                    }
                    img.removed = !img.removed;
                    showError("");
                    render();
                }, img.removed));
            });

            files.forEach((file, index) => {
                const url = URL.createObjectURL(file);
                const item = tile(url, file.name, () => {
                    files.splice(index, 1);
                    syncInput();
                    showError("");
                    render();
                }, false);
                item.querySelector("img").addEventListener("load", () => URL.revokeObjectURL(url), { once: true });
                item.classList.add("is-new");
                list.append(item);
            });

            // Hidden inputs tell the server which saved pictures to delete.
            form.querySelectorAll('input[name="removeImages[]"]').forEach((el) => el.remove());
            existing.filter((img) => img.removed).forEach((img) => {
                const hidden = document.createElement("input");
                hidden.type = "hidden";
                hidden.name = "removeImages[]";
                hidden.value = img.id;
                form.append(hidden);
            });

            list.hidden = list.children.length === 0;
            drop.hidden = keptCount() >= max;
        }

        function addFiles(newFiles) {
            const rejected = [];

            Array.from(newFiles).forEach((file) => {
                if (!TYPES.includes(file.type)) {
                    rejected.push("“" + file.name + "” is not a JPG, PNG or WEBP picture.");
                } else if (file.size > MAX_BYTES) {
                    rejected.push("“" + file.name + "” is larger than 5 MB.");
                } else if (keptCount() >= max) {
                    rejected.push("Only " + max + " pictures can be added.");
                } else {
                    files.push(file);
                }
            });

            syncInput();
            showError([...new Set(rejected)].join(" "));
            render();
        }

        input.addEventListener("change", () => {
            const chosen = Array.from(input.files);
            syncInput();       // restore the list kept so far…
            addFiles(chosen);  // …then add the new choice
        });

        ["dragenter", "dragover"].forEach((name) => drop.addEventListener(name, (event) => {
            event.preventDefault();
            drop.classList.add("is-dragging");
        }));
        ["dragleave", "drop"].forEach((name) => drop.addEventListener(name, () => drop.classList.remove("is-dragging")));
        drop.addEventListener("drop", (event) => {
            event.preventDefault();
            if (event.dataTransfer && event.dataTransfer.files.length) addFiles(event.dataTransfer.files);
        });

        picker.reset = function (existingImages) {
            files = [];
            existing = (existingImages || []).map((img) => ({ id: img.id, url: img.url, removed: false }));
            syncInput();
            showError("");
            render();
        };

        picker.reset([]);
    }

    document.querySelectorAll("[data-photo-picker]").forEach(setupPicker);

    // Show progress while pictures upload.
    document.querySelectorAll("[data-announcement-form]").forEach((form) => {
        form.addEventListener("submit", () => {
            const button = form.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = true;
                button.textContent = form.querySelector("[data-photo-input]")?.files.length ? "Uploading…" : "Saving…";
            }
        });
    });

    // A fresh, empty picker each time "New Announcement" opens.
    document.addEventListener("click", (event) => {
        if (!(event.target instanceof Element)) return;
        if (event.target.closest('[data-modal-open="createModal"]')) {
            const picker = document.querySelector("#createModal [data-photo-picker]");
            if (picker && picker.reset) picker.reset([]);
        }
    }, true);

    /* ------------------------------------------------------------------ */
    /* 2. Edit form                                                        */
    /* ------------------------------------------------------------------ */

    document.addEventListener("click", (event) => {
        if (!(event.target instanceof Element)) return;
        const button = event.target.closest(".ann-edit-btn");
        if (!button) return;

        document.getElementById("editAnnouncementID").value = button.dataset.id;
        document.getElementById("editTitle").value = button.dataset.title;
        document.getElementById("editContent").value = button.dataset.content;

        const picker = document.querySelector("#editModal [data-photo-picker]");
        if (picker && picker.reset) {
            let images = [];
            try {
                images = JSON.parse(button.dataset.images || "[]");
            } catch (error) {
                images = [];
            }
            picker.reset(images);
        }

        CS.openModal("editModal");
    });

    /* ------------------------------------------------------------------ */
    /* 3. Picture viewer                                                   */
    /* ------------------------------------------------------------------ */

    const lightbox = document.getElementById("annLightbox");
    const lightboxImage = document.getElementById("annLightboxImage");
    const lightboxCount = document.getElementById("annLightboxCount");
    let gallery = [];
    let position = 0;

    function showPicture(index) {
        position = (index + gallery.length) % gallery.length;
        lightboxImage.src = gallery[position];
        lightboxCount.textContent = gallery.length > 1 ? "Picture " + (position + 1) + " of " + gallery.length : "";
        lightbox.querySelectorAll(".ann-lightbox-nav").forEach((nav) => { nav.hidden = gallery.length < 2; });
    }

    document.addEventListener("click", (event) => {
        if (!(event.target instanceof Element)) return;

        const photo = event.target.closest("[data-lightbox]");
        if (photo) {
            const group = photo.dataset.lightbox;
            gallery = [
                ...document.querySelectorAll('[data-lightbox="' + group + '"]'),
                ...document.querySelectorAll('[data-lightbox-extra="' + group + '"]')
            ].map((el) => el.dataset.src);
            const start = [...document.querySelectorAll('[data-lightbox="' + group + '"]')].indexOf(photo);
            showPicture(Math.max(0, start));
            CS.openModal(lightbox);
            return;
        }

        const step = event.target.closest("[data-lightbox-step]");
        if (step) showPicture(position + Number(step.dataset.lightboxStep));
    });

    document.addEventListener("keydown", (event) => {
        if (!lightbox.classList.contains("is-open") || gallery.length < 2) return;
        if (event.key === "ArrowRight") showPicture(position + 1);
        if (event.key === "ArrowLeft") showPicture(position - 1);
    });
})();
