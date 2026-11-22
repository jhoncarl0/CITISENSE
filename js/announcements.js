/* ==========================================================================
   CitiSense Admin — Announcements
   --------------------------------------------------------------------------
   1. Picture picker (add / preview / remove before saving, drag and drop)
   2. Edit form (fills in text and existing pictures)
   3. Picture viewer (lightbox with previous / next)
   4. Comments under each announcement (post, reply, hide / show, delete)
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

/* ------------------------------------------------------------------ */
/* 4. Comments under each announcement                                 */
/* ------------------------------------------------------------------ */

(function () {
    "use strict";

    const CS = window.CitiSense;

    async function postJSON(body) {
        const response = await fetch("announcement_comments_api.php", {
            method: "POST",
            headers: { "Content-Type": "application/json", Accept: "application/json" },
            body: JSON.stringify(body)
        });
        let data = null;
        try {
            data = await response.json();
        } catch (error) {
            throw new Error("The server sent an unexpected reply. Please try again.");
        }
        if (!response.ok || !data || !data.success) {
            throw new Error((data && data.message) || "Something went wrong. Please try again.");
        }
        return data;
    }

    function toggleFor(panel) {
        return document.querySelector('[data-comments-toggle="' + panel.dataset.announceId + '"]');
    }

    function fromHTML(html) {
        const template = document.createElement("template");
        template.innerHTML = html.trim();
        return template.content.firstElementChild;
    }

    /** Update the "N comments" text and the empty message after a change. */
    function refresh(panel, data) {
        const toggle = toggleFor(panel);
        if (toggle && typeof data.summary === "string") {
            toggle.querySelector("[data-comments-summary]").textContent = data.summary;
        }
        const empty = panel.querySelector("[data-comments-empty]");
        if (empty) empty.hidden = panel.querySelectorAll(".ann-comment").length > 0;
    }

    function markRead(panel) {
        const toggle = toggleFor(panel);
        if (!toggle || !toggle.classList.contains("has-unread")) return;
        toggle.classList.remove("has-unread");
        const badge = toggle.querySelector("[data-comments-new]");
        if (badge) badge.remove();
        postJSON({ action: "read", announceID: Number(panel.dataset.announceId) }).catch(() => {
            /* Not important enough to bother the user: it is retried next time. */
        });
    }

    function setOpen(panel, open, focus) {
        const toggle = toggleFor(panel);
        panel.hidden = !open;
        if (toggle) toggle.setAttribute("aria-expanded", open ? "true" : "false");
        if (!open) return;
        markRead(panel);
        const list = panel.querySelector("[data-comment-list]");
        if (list && list.scrollHeight > list.clientHeight) list.scrollTop = list.scrollHeight;
        if (focus) panel.querySelector("textarea").focus({ preventScroll: true });
    }

    // Open / close
    document.addEventListener("click", (event) => {
        if (!(event.target instanceof Element)) return;
        const toggle = event.target.closest("[data-comments-toggle]");
        if (!toggle) return;
        const panel = document.getElementById("comments-" + toggle.dataset.commentsToggle);
        if (panel) setOpen(panel, panel.hidden, false);
    });

    // Post a comment
    document.querySelectorAll("[data-comment-form]").forEach((form) => {
        const panel = form.closest(".ann-comments");
        const textarea = form.querySelector("textarea");
        const button = form.querySelector('button[type="submit"]');

        // Ctrl/Cmd + Enter posts; plain Enter makes a new line.
        textarea.addEventListener("keydown", (event) => {
            if (event.key === "Enter" && (event.ctrlKey || event.metaKey)) {
                event.preventDefault();
                form.requestSubmit();
            }
        });

        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            const message = textarea.value.trim();
            if (!message) {
                CS.toast("Please write a comment first.", "error");
                textarea.focus();
                return;
            }

            const original = button.innerHTML;
            button.disabled = true;
            button.textContent = "Posting…";

            try {
                const data = await postJSON({ action: "add", announceID: Number(panel.dataset.announceId), message });
                const list = panel.querySelector("[data-comment-list]");
                const item = fromHTML(data.html);
                if (item) {
                    list.appendChild(item);
                    list.scrollTop = list.scrollHeight;
                }
                textarea.value = "";
                refresh(panel, data);
                CS.toast(data.message, "success");
            } catch (error) {
                CS.toast(error.message, "error");
            } finally {
                button.disabled = false;
                button.innerHTML = original;
            }
        });
    });

    // Reply to a specific comment (like Facebook): a small form under the top-level comment
    function replyForm(rootItem) {
        let form = rootItem.querySelector(":scope > .ann-reply-form");
        if (form) return form;

        const id = "replyInput" + rootItem.dataset.rootId;
        form = document.createElement("form");
        form.className = "ann-reply-form";
        form.innerHTML =
            '<div class="ann-reply-label"><i class="bi bi-reply-fill" aria-hidden="true"></i> ' +
            '<span>Replying to <strong data-reply-name></strong></span>' +
            '<button type="button" class="ann-comment-action" data-reply-cancel>Cancel</button></div>' +
            '<label class="cs-sr-only" for="' + id + '">Write a reply</label>' +
            '<div class="ann-reply-row">' +
            '<textarea id="' + id + '" class="cs-textarea" rows="1" maxlength="2000" placeholder="Write a reply… (Ctrl + Enter to post)" required></textarea>' +
            '<button type="submit" class="cs-btn cs-btn-primary cs-btn-sm"><i class="bi bi-send" aria-hidden="true"></i> Reply</button>' +
            "</div>";
        rootItem.appendChild(form);

        const textarea = form.querySelector("textarea");
        const button = form.querySelector('button[type="submit"]');
        const panel = rootItem.closest(".ann-comments");

        textarea.addEventListener("keydown", (event) => {
            if (event.key === "Enter" && (event.ctrlKey || event.metaKey)) {
                event.preventDefault();
                form.requestSubmit();
            }
            if (event.key === "Escape") form.remove();
        });
        form.querySelector("[data-reply-cancel]").addEventListener("click", () => form.remove());

        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            const message = textarea.value.trim();
            if (!message) {
                CS.toast("Please write a reply first.", "error");
                textarea.focus();
                return;
            }

            const original = button.innerHTML;
            button.disabled = true;
            button.textContent = "Posting…";
            try {
                const data = await postJSON({
                    action: "add",
                    announceID: Number(panel.dataset.announceId),
                    parentID: Number(form.dataset.parentId),
                    message
                });
                const list = rootItem.querySelector(":scope > .ann-replies");
                const item = fromHTML(data.html);
                if (item && list) {
                    list.appendChild(item);
                    item.scrollIntoView({ block: "nearest", behavior: "smooth" });
                }
                form.remove();
                refresh(panel, data);
                CS.toast(data.message, "success");
            } catch (error) {
                CS.toast(error.message, "error");
                button.disabled = false;
                button.innerHTML = original;
            }
        });

        return form;
    }

    document.addEventListener("click", (event) => {
        if (!(event.target instanceof Element)) return;
        const reply = event.target.closest("[data-comment-reply]");
        if (!reply) return;

        const panel = reply.closest(".ann-comments");
        const rootItem = panel.querySelector('[data-root-id="' + reply.dataset.root + '"]');
        if (!rootItem) return;

        const form = replyForm(rootItem);
        form.dataset.parentId = reply.dataset.commentReply;
        form.querySelector("[data-reply-name]").textContent = reply.dataset.name;
        const textarea = form.querySelector("textarea");
        textarea.focus();
        form.scrollIntoView({ block: "nearest", behavior: "smooth" });
    });

    // Hide / show a resident's comment, delete the barangay's own comment
    document.addEventListener("click", async (event) => {
        if (!(event.target instanceof Element)) return;
        const hide = event.target.closest("[data-comment-hide]");
        const remove = event.target.closest("[data-comment-delete]");
        if (!hide && !remove) return;

        const button = hide || remove;
        const item = button.closest(".ann-comment");
        const panel = button.closest(".ann-comments");

        let ok;
        let body;
        if (hide) {
            const hiding = hide.dataset.hidden === "1";
            ok = await CS.confirm({
                title: hiding ? "Hide this comment?" : "Show this comment again?",
                message: hiding
                    ? "Residents will no longer see this comment under the announcement. You can show it again later."
                    : "Residents will see this comment under the announcement again.",
                confirmText: hiding ? "Yes, Hide" : "Yes, Show",
                danger: hiding,
                icon: hiding ? "bi-eye-slash" : "bi-eye"
            });
            body = { action: "hide", commentID: Number(hide.dataset.commentHide), hidden: hiding };
        } else {
            // Replies below this comment (direct and further down) are removed too.
            let replyCount = 0;
            const queue = [item.dataset.commentId];
            const seen = new Set(queue);
            while (queue.length) {
                const parent = queue.shift();
                panel.querySelectorAll('[data-parent-id="' + parent + '"]').forEach((child) => {
                    if (seen.has(child.dataset.commentId)) return;
                    seen.add(child.dataset.commentId);
                    queue.push(child.dataset.commentId);
                    replyCount++;
                });
            }
            ok = await CS.confirm({
                title: "Delete your comment?",
                message: replyCount > 0
                    ? "This comment and its " + replyCount + (replyCount === 1 ? " reply" : " replies") + " will be removed for everyone. This cannot be undone."
                    : "This comment will be removed for everyone. This cannot be undone.",
                confirmText: "Yes, Delete",
                danger: true,
                icon: "bi-trash"
            });
            body = { action: "delete", commentID: Number(remove.dataset.commentDelete) };
        }
        if (!ok) return;

        button.disabled = true;
        try {
            const data = await postJSON(body);
            if (remove) {
                (data.removed || []).forEach((id) => {
                    const gone = panel.querySelector('[data-comment-id="' + id + '"]');
                    if (gone) gone.remove();
                });
                if (item.isConnected) item.remove();
            } else {
                const updated = fromHTML(data.html);
                if (updated) item.replaceWith(updated);
            }
            refresh(panel, data);
            CS.toast(data.message, "success");
        } catch (error) {
            button.disabled = false;
            CS.toast(error.message, "error");
        }
    });

    // Links like announcements.php#comments-34 open those comments straight away.
    const match = /^#comments-(\d+)$/.exec(window.location.hash);
    if (match) {
        const panel = document.getElementById("comments-" + match[1]);
        if (panel) {
            setOpen(panel, true, false);
            panel.closest(".ann-card").scrollIntoView({ block: "start", behavior: "smooth" });
        }
    }
})();
