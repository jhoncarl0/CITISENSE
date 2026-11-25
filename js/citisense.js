/* ==========================================================================
   CitiSense Admin — shared UI behaviour
   --------------------------------------------------------------------------
   window.CitiSense exposes:
     toast(message, type)               "success" | "error" | "info"
     confirm({ title, message, ... })   Promise<boolean>
     openModal(idOrElement) / closeModal(idOrElement)
     flashAfterReload(message, type)    show a toast after the page reloads

     enhanceSelect(select)              custom dropdown (done automatically
                                        for every select.cs-select)

   Declarative hooks:
     [data-sidebar-toggle] / [data-sidebar-close]
     [data-modal-open="id"] / [data-modal-close]
     [data-confirm="message"] on links, buttons and forms
     [data-dismiss-alert]
   ========================================================================== */

(function () {
    "use strict";

    const FLASH_KEY = "citisense-flash";
    const openModals = [];

    /* ---------------------------------------------------------------- toast */

    function toast(message, type) {
        const region = document.getElementById("csToastRegion");
        if (!region || !message) return;

        const kind = type === "error" ? "error" : type === "info" ? "info" : "success";
        const icon = kind === "error"
            ? "bi-exclamation-circle-fill"
            : kind === "info" ? "bi-info-circle-fill" : "bi-check-circle-fill";

        const item = document.createElement("div");
        item.className = "cs-toast cs-toast-" + kind;

        const i = document.createElement("i");
        i.className = "bi " + icon;
        i.setAttribute("aria-hidden", "true");

        const text = document.createElement("span");
        text.textContent = message;

        item.append(i, text);
        region.appendChild(item);

        // Long enough to read comfortably; errors stay a little longer.
        setTimeout(() => item.remove(), kind === "error" ? 8000 : 5000);
    }

    function flashAfterReload(message, type) {
        try {
            sessionStorage.setItem(FLASH_KEY, JSON.stringify({ message, type }));
        } catch (e) {
            /* storage unavailable: nothing to show later */
        }
    }

    function showPendingFlash() {
        try {
            const raw = sessionStorage.getItem(FLASH_KEY);
            if (!raw) return;
            sessionStorage.removeItem(FLASH_KEY);
            const data = JSON.parse(raw);
            toast(data.message, data.type);
        } catch (e) {
            /* ignore */
        }
    }

    /* --------------------------------------------------------------- modals */

    function resolveModal(target) {
        return typeof target === "string" ? document.getElementById(target) : target;
    }

    function openModal(target) {
        const modal = resolveModal(target);
        if (!modal) return;

        modal._returnFocus = document.activeElement;
        modal.classList.add("is-open");
        document.body.classList.add("cs-no-scroll");

        if (!openModals.includes(modal)) openModals.push(modal);

        const focusable = modal.querySelector(
            "[autofocus], input:not([type=hidden]):not([disabled]), textarea, select:not([data-cs-enhanced]), button:not([disabled])"
        );
        if (focusable) setTimeout(() => focusable.focus(), 30);
    }

    function closeModal(target) {
        const modal = resolveModal(target);
        if (!modal || !modal.classList.contains("is-open")) return;

        modal.classList.remove("is-open");

        const index = openModals.indexOf(modal);
        if (index > -1) openModals.splice(index, 1);

        if (!openModals.length && !document.querySelector(".cs-drawer.is-open")) {
            document.body.classList.remove("cs-no-scroll");
        }

        if (modal._returnFocus && modal._returnFocus.isConnected) {
            modal._returnFocus.focus();
        }

        modal.dispatchEvent(new CustomEvent("cs:modal-closed"));
    }

    /* -------------------------------------------------------------- confirm */

    let confirmResolver = null;

    function confirmDialog(options) {
        const modal = document.getElementById("csConfirmModal");
        const opts = options || {};

        if (!modal) {
            return Promise.resolve(window.confirm(opts.message || "Are you sure?"));
        }

        modal.querySelector("#csConfirmTitle").textContent = opts.title || "Are you sure?";
        modal.querySelector("#csConfirmMessage").textContent = opts.message || "";

        const iconWrap = modal.querySelector("#csConfirmIcon");
        iconWrap.classList.toggle("is-danger", !!opts.danger);
        iconWrap.innerHTML = "";
        const icon = document.createElement("i");
        icon.className = "bi " + (opts.icon || (opts.danger ? "bi-exclamation-triangle" : "bi-question-lg"));
        icon.setAttribute("aria-hidden", "true");
        iconWrap.appendChild(icon);

        const ok = modal.querySelector("[data-confirm-ok]");
        ok.textContent = opts.confirmText || "Confirm";
        ok.className = "cs-btn " + (opts.danger ? "cs-btn-danger" : "cs-btn-primary");

        modal.querySelector("[data-confirm-cancel]").textContent = opts.cancelText || "Cancel";

        if (confirmResolver) confirmResolver(false);

        return new Promise((resolve) => {
            confirmResolver = resolve;
            openModal(modal);
            setTimeout(() => ok.focus(), 40);
        });
    }

    function settleConfirm(value) {
        const modal = document.getElementById("csConfirmModal");
        const resolver = confirmResolver;
        confirmResolver = null;
        closeModal(modal);
        if (resolver) resolver(value);
    }

    function confirmOptionsFrom(el) {
        return {
            title: el.dataset.confirmTitle,
            message: el.dataset.confirm,
            confirmText: el.dataset.confirmButton,
            danger: el.dataset.confirmDanger === "true",
            icon: el.dataset.confirmIcon
        };
    }

    /* -------------------------------------------------------------- sidebar */

    function setSidebar(open) {
        const sidebar = document.getElementById("csSidebar");
        const backdrop = document.querySelector(".cs-sidebar-backdrop");
        const toggle = document.querySelector("[data-sidebar-toggle]");
        if (!sidebar) return;

        sidebar.classList.toggle("is-open", open);
        if (backdrop) backdrop.classList.toggle("is-open", open);
        if (toggle) toggle.setAttribute("aria-expanded", open ? "true" : "false");

        if (open) {
            const first = sidebar.querySelector(".cs-nav-item");
            if (first) first.focus();
        }
    }

    /* --------------------------------------------------------------- events */

    document.addEventListener("click", function (event) {
        const target = event.target;
        if (!(target instanceof Element)) return;

        if (target.closest("[data-sidebar-toggle]")) {
            const sidebar = document.getElementById("csSidebar");
            setSidebar(!(sidebar && sidebar.classList.contains("is-open")));
            return;
        }

        if (target.closest("[data-sidebar-close]")) {
            setSidebar(false);
            return;
        }

        if (target.closest("[data-confirm-ok]")) {
            settleConfirm(true);
            return;
        }

        if (target.closest("[data-confirm-cancel]")) {
            settleConfirm(false);
            return;
        }

        const opener = target.closest("[data-modal-open]");
        if (opener) {
            event.preventDefault();
            openModal(opener.dataset.modalOpen);
            return;
        }

        const closer = target.closest("[data-modal-close]");
        if (closer) {
            closeModal(closer.closest(".cs-modal"));
            return;
        }

        // Clicking the dark area around a dialog closes it.
        if (target.classList.contains("cs-modal")) {
            if (target.id === "csConfirmModal") settleConfirm(false);
            else closeModal(target);
            return;
        }

        const dismiss = target.closest("[data-dismiss-alert]");
        if (dismiss) {
            const alert = dismiss.closest(".cs-alert");
            if (alert) alert.remove();
            return;
        }

        // Links that need confirmation (e.g. Log Out).
        const confirmLink = target.closest("a[data-confirm]");
        if (confirmLink) {
            event.preventDefault();
            confirmDialog(confirmOptionsFrom(confirmLink)).then((ok) => {
                if (ok) window.location.href = confirmLink.href;
            });
        }
    });

    // Forms that need confirmation (e.g. Delete).
    document.addEventListener("submit", function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.dataset.confirm) return;
        if (form.dataset.confirmed === "1") return;

        event.preventDefault();
        confirmDialog(confirmOptionsFrom(form)).then((ok) => {
            if (!ok) return;
            form.dataset.confirmed = "1";
            form.requestSubmit ? form.requestSubmit() : form.submit();
        });
    });

    document.addEventListener("keydown", function (event) {
        if (event.key !== "Escape") return;

        const top = openModals[openModals.length - 1];
        if (top) {
            if (top.id === "csConfirmModal") settleConfirm(false);
            else closeModal(top);
            return;
        }

        const sidebar = document.getElementById("csSidebar");
        if (sidebar && sidebar.classList.contains("is-open")) setSidebar(false);
    });

    // A photo whose file is missing shows a clear placeholder instead of a
    // broken-image icon. (Image errors do not bubble, so listen in capture.)
    function showMissingPhoto(img) {
        if (!(img instanceof HTMLImageElement) || img.dataset.failed) return;

        const box = img.closest(".rp-thumb, .rp-photo, .cs-avatar, .duplicate-report-image, .submission-image");
        if (!box) return;

        img.dataset.failed = "1";
        const placeholder = document.createElement("span");
        placeholder.className = "cs-photo-missing";
        placeholder.title = "The photo file could not be found on the server.";

        const i = document.createElement("i");
        i.className = "bi " + (box.classList.contains("cs-avatar") ? "bi-person" : "bi-image");
        i.setAttribute("aria-hidden", "true");
        placeholder.appendChild(i);

        if (box.classList.contains("rp-photo")) {
            const text = document.createElement("span");
            text.textContent = "Photo not found on the server";
            placeholder.appendChild(text);
            box.removeAttribute("href");
            box.querySelector(".rp-photo-hint")?.remove();
        }

        img.replaceWith(placeholder);
    }

    document.addEventListener("error", (event) => showMissingPhoto(event.target), true);

    // Images that already failed before this script loaded.
    function checkLoadedImages() {
        document.querySelectorAll("img").forEach((img) => {
            if (img.complete && img.naturalWidth === 0 && img.getAttribute("src")) showMissingPhoto(img);
        });
    }

    if (document.readyState === "complete") checkLoadedImages();
    else window.addEventListener("load", checkLoadedImages);

    window.addEventListener("resize", function () {
        if (window.innerWidth > 1024) setSidebar(false);
    });

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", showPendingFlash);
    } else {
        showPendingFlash();
    }

    /* ------------------------------------------------------ map zoom lock */

    // Stops zoom gestures that start over `element` (a map) from zooming
    // the whole page. The map library handles the zoom itself.
    function lockPageZoom(element) {
        if (!element || element.dataset.zoomLocked) return;
        element.dataset.zoomLocked = "1";

        // Mouse wheel and trackpad pinch (browsers report pinch as ctrl+wheel)
        element.addEventListener("wheel", (event) => event.preventDefault(), { passive: false });

        // Two-finger pinch on touch screens
        element.addEventListener("touchmove", (event) => {
            if (event.touches.length > 1) event.preventDefault();
        }, { passive: false });

        // Safari trackpad / iPad pinch
        ["gesturestart", "gesturechange", "gestureend"].forEach((name) => {
            element.addEventListener(name, (event) => event.preventDefault(), { passive: false });
        });
    }

    /* ------------------------------------------------- custom dropdowns */

    /*
     * Every <select class="cs-select"> gets the same dropdown on every
     * device (iPhone, Android, Windows, Mac) instead of the browser's own
     * picker. The real <select> stays in the page, hidden, and is always
     * kept in step, so forms submit it, "required" still works, and page
     * scripts can keep reading/writing select.value, changing its options
     * or listening for "change". Add data-native to a select to opt out.
     */
    const nativeValue = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, "value");
    const nativeIndex = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, "selectedIndex");
    let dropdownCount = 0;
    let openDropdown = null;

    function enhanceSelect(select) {
        if (!(select instanceof HTMLSelectElement) || select.dataset.csEnhanced || select.multiple ||
            select.size > 1 || select.hasAttribute("data-native")) return;
        select.dataset.csEnhanced = "1";

        const uid = "csdd" + (++dropdownCount);
        const wrap = document.createElement("div");
        // Layout classes of the select (e.g. widths) move to the wrapper.
        wrap.className = "cs-dropdown " + Array.from(select.classList).filter((c) => c !== "cs-select").join(" ");

        const button = document.createElement("button");
        button.type = "button";
        button.className = "cs-select cs-dropdown-button";
        button.id = uid + "-button";
        button.setAttribute("aria-haspopup", "listbox");
        button.setAttribute("aria-expanded", "false");
        button.setAttribute("aria-controls", uid + "-menu");
        const buttonText = document.createElement("span");
        buttonText.className = "cs-dropdown-text";
        button.appendChild(buttonText);

        const menu = document.createElement("ul");
        menu.className = "cs-dropdown-menu";
        menu.id = uid + "-menu";
        menu.setAttribute("role", "listbox");
        menu.tabIndex = -1;
        menu.hidden = true;

        // Name the button after the select's <label> (and keep labels working).
        const labels = select.id ? document.querySelectorAll('label[for="' + CSS.escape(select.id) + '"]') : [];
        const labelIDs = [];
        labels.forEach((label, i) => {
            if (!label.id) label.id = uid + "-label" + i;
            labelIDs.push(label.id);
            label.addEventListener("click", (event) => {
                event.preventDefault();
                button.focus();
            });
        });
        if (select.getAttribute("aria-label")) button.setAttribute("aria-label", select.getAttribute("aria-label"));
        if (labelIDs.length) {
            button.setAttribute("aria-labelledby", labelIDs.join(" ") + " " + button.id);
            menu.setAttribute("aria-labelledby", labelIDs.join(" "));
        }

        select.parentNode.insertBefore(wrap, select);
        wrap.append(select, button);
        document.body.appendChild(menu); // fixed position: never cut off by a dialog or card
        select.classList.add("cs-dropdown-native");
        select.tabIndex = -1;
        select.setAttribute("aria-hidden", "true");

        let active = -1;
        let typed = "";
        let typedTimer = 0;

        const options = () => Array.from(select.options);
        const usable = (option) => option && !option.disabled && !option.hidden;

        function render() {
            const current = select.options[nativeIndex.get.call(select)];
            buttonText.textContent = current ? current.textContent.trim() : "";
            wrap.classList.toggle("is-placeholder", !current || current.value === "");
            button.disabled = select.disabled;
            wrap.classList.toggle("is-disabled", select.disabled);
            if (!menu.hidden) build();
        }

        function build() {
            const selected = nativeIndex.get.call(select);
            menu.replaceChildren(...options().map((option, index) => {
                const item = document.createElement("li");
                item.id = uid + "-opt" + index;
                item.className = "cs-dropdown-option";
                item.setAttribute("role", "option");
                item.dataset.index = String(index);
                item.setAttribute("aria-selected", index === selected ? "true" : "false");
                if (!usable(option)) {
                    item.setAttribute("aria-disabled", "true");
                    if (option.hidden) item.hidden = true;
                }
                const text = document.createElement("span");
                text.textContent = option.textContent.trim();
                const check = document.createElement("i");
                check.className = "bi bi-check2";
                check.setAttribute("aria-hidden", "true");
                item.append(text, check);
                return item;
            }));
            setActive(active);
        }

        function setActive(index) {
            active = index;
            menu.querySelectorAll(".is-active").forEach((item) => item.classList.remove("is-active"));
            const item = menu.querySelector('[data-index="' + index + '"]');
            if (item) {
                item.classList.add("is-active");
                menu.setAttribute("aria-activedescendant", item.id);
                const top = item.offsetTop;
                const bottom = top + item.offsetHeight;
                if (top < menu.scrollTop) menu.scrollTop = top;
                else if (bottom > menu.scrollTop + menu.clientHeight) menu.scrollTop = bottom - menu.clientHeight;
            } else {
                menu.removeAttribute("aria-activedescendant");
            }
        }

        function move(step) {
            const list = options();
            let index = active;
            for (let n = 0; n < list.length; n++) {
                index = Math.max(0, Math.min(list.length - 1, index + step));
                if (usable(list[index])) return setActive(index);
                if ((step < 0 && index === 0) || (step > 0 && index === list.length - 1)) break;
            }
        }

        function edge(first) {
            const list = options();
            const order = first ? list.map((_, i) => i) : list.map((_, i) => list.length - 1 - i);
            const found = order.find((i) => usable(list[i]));
            if (found !== undefined) setActive(found);
        }

        function place() {
            const box = button.getBoundingClientRect();
            const gap = 6;
            const margin = 8;
            const below = window.innerHeight - box.bottom - gap - margin;
            const above = box.top - gap - margin;
            const wanted = Math.min(menu.scrollHeight || 300, 300);
            const up = below < Math.min(wanted, 180) && above > below;
            const width = Math.min(Math.max(box.width, 200), window.innerWidth - margin * 2);
            const left = Math.min(Math.max(margin, box.left), window.innerWidth - width - margin);

            menu.style.width = width + "px";
            menu.style.left = left + "px";
            menu.style.maxHeight = Math.max(120, Math.min(300, up ? above : below)) + "px";
            if (up) {
                menu.style.top = "auto";
                menu.style.bottom = (window.innerHeight - box.top + gap) + "px";
            } else {
                menu.style.bottom = "auto";
                menu.style.top = (box.bottom + gap) + "px";
            }
            menu.classList.toggle("is-up", up);
        }

        function open() {
            if (select.disabled || !menu.hidden) return;
            if (openDropdown && openDropdown !== api) openDropdown.close(false);
            openDropdown = api;
            active = nativeIndex.get.call(select);
            menu.hidden = false;
            build();
            place();
            setActive(active < 0 ? -1 : active);
            if (active < 0) edge(true);
            wrap.classList.add("is-open");
            button.setAttribute("aria-expanded", "true");
            menu.focus({ preventScroll: true });
        }

        function close(returnFocus) {
            if (menu.hidden) return;
            menu.hidden = true;
            wrap.classList.remove("is-open");
            button.setAttribute("aria-expanded", "false");
            if (openDropdown === api) openDropdown = null;
            if (returnFocus !== false) button.focus({ preventScroll: true });
        }

        function choose(index) {
            const option = select.options[index];
            if (!usable(option)) return;
            const changed = nativeIndex.get.call(select) !== index;
            nativeIndex.set.call(select, index);
            render();
            close();
            if (changed) {
                select.dispatchEvent(new Event("input", { bubbles: true }));
                select.dispatchEvent(new Event("change", { bubbles: true }));
            }
        }

        const api = { close, place, contains: (node) => wrap.contains(node) || menu.contains(node) };

        button.addEventListener("click", () => (menu.hidden ? open() : close()));
        button.addEventListener("keydown", (event) => {
            if (["ArrowDown", "ArrowUp", "Enter", " "].includes(event.key)) {
                event.preventDefault();
                open();
            }
        });

        menu.addEventListener("click", (event) => {
            const item = event.target instanceof Element && event.target.closest(".cs-dropdown-option");
            if (item && item.getAttribute("aria-disabled") !== "true") choose(Number(item.dataset.index));
        });
        menu.addEventListener("mousemove", (event) => {
            const item = event.target instanceof Element && event.target.closest(".cs-dropdown-option");
            if (item && item.getAttribute("aria-disabled") !== "true" && Number(item.dataset.index) !== active) {
                setActive(Number(item.dataset.index));
            }
        });
        menu.addEventListener("keydown", (event) => {
            switch (event.key) {
                case "ArrowDown": event.preventDefault(); move(1); break;
                case "ArrowUp": event.preventDefault(); move(-1); break;
                case "Home": event.preventDefault(); edge(true); break;
                case "End": event.preventDefault(); edge(false); break;
                case "PageDown": event.preventDefault(); move(8); break;
                case "PageUp": event.preventDefault(); move(-8); break;
                case "Enter":
                case " ":
                    event.preventDefault();
                    if (active >= 0) choose(active);
                    break;
                case "Escape":
                    event.preventDefault();
                    event.stopPropagation(); // do not close the dialog behind it
                    close();
                    break;
                case "Tab":
                    if (active >= 0) choose(active);
                    else close();
                    break;
                default:
                    if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
                        // Type to jump to an option.
                        clearTimeout(typedTimer);
                        typed += event.key.toLowerCase();
                        typedTimer = setTimeout(() => { typed = ""; }, 700);
                        const list = options();
                        const found = list.findIndex((option, i) => usable(option) &&
                            option.textContent.trim().toLowerCase().startsWith(typed));
                        if (found > -1) setActive(found);
                    }
            }
        });
        menu.addEventListener("focusout", (event) => {
            if (!event.relatedTarget || !api.contains(event.relatedTarget)) close(false);
        });

        // Focus that lands on the hidden select (labels, scripts) goes to the button.
        select.addEventListener("focus", () => button.focus());
        // The browser's "please choose" message for required selects.
        select.addEventListener("invalid", () => wrap.classList.add("is-invalid"));
        select.addEventListener("change", () => {
            wrap.classList.remove("is-invalid");
            render();
        });

        // Keep in step when page scripts change the select.
        Object.defineProperty(select, "value", {
            configurable: true,
            get() { return nativeValue.get.call(this); },
            set(value) { nativeValue.set.call(this, value); render(); }
        });
        Object.defineProperty(select, "selectedIndex", {
            configurable: true,
            get() { return nativeIndex.get.call(this); },
            set(value) { nativeIndex.set.call(this, value); render(); }
        });
        new MutationObserver(render).observe(select, {
            childList: true, subtree: true, attributes: true, characterData: true,
            attributeFilter: ["disabled", "selected", "label", "hidden"]
        });
        if (select.form) select.form.addEventListener("reset", () => setTimeout(render, 0));

        render();
    }

    function enhanceAllSelects(root) {
        (root || document).querySelectorAll("select.cs-select").forEach(enhanceSelect);
    }

    // Close an open dropdown when clicking elsewhere, scrolling the page or resizing.
    document.addEventListener("pointerdown", (event) => {
        if (openDropdown && !openDropdown.contains(event.target)) openDropdown.close(false);
    }, true);
    window.addEventListener("resize", () => openDropdown && openDropdown.close(false));
    document.addEventListener("scroll", (event) => {
        if (!openDropdown) return;
        if (event.target instanceof Element && event.target.classList.contains("cs-dropdown-menu")) return;
        openDropdown.place();
    }, true);

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", () => enhanceAllSelects());
    } else {
        enhanceAllSelects();
    }

    window.CitiSense = {
        enhanceSelect,
        lockPageZoom,
        toast,
        confirm: confirmDialog,
        openModal,
        closeModal,
        flashAfterReload
    };
})();
