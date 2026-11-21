/* ==========================================================================
   CitiSense Admin — shared UI behaviour
   --------------------------------------------------------------------------
   window.CitiSense exposes:
     toast(message, type)               "success" | "error" | "info"
     confirm({ title, message, ... })   Promise<boolean>
     openModal(idOrElement) / closeModal(idOrElement)
     flashAfterReload(message, type)    show a toast after the page reloads

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
            "[autofocus], input:not([type=hidden]):not([disabled]), textarea, select, button:not([disabled])"
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

    window.CitiSense = {
        lockPageZoom,
        toast,
        confirm: confirmDialog,
        openModal,
        closeModal,
        flashAfterReload
    };
})();
