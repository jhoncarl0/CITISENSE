/* ==========================================================================
   CitiSense Admin — Messages
   One conversation per resident: send a general message or one about a
   report, reply to a specific message, delete messages, show / hide the
   resident's profile, keep the view scrolled to the newest message.
   ========================================================================== */

(function () {
    "use strict";

    const CS = window.CitiSense;
    const list = document.getElementById("msgMessages");
    const form = document.getElementById("msgReplyForm");

    if (list) list.scrollTop = list.scrollHeight;

    // Profile panel
    const profileToggle = document.querySelector("[data-profile-toggle]");
    const profile = document.getElementById("msgProfile");
    function setProfile(open) {
        profile.hidden = !open;
        profileToggle.setAttribute("aria-expanded", open ? "true" : "false");
        if (open) profile.querySelector("[data-profile-close]").focus();
        else profileToggle.focus();
    }
    if (profileToggle && profile) {
        profileToggle.addEventListener("click", () => setProfile(profile.hidden));
        profile.querySelector("[data-profile-close]").addEventListener("click", () => setProfile(false));
        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape" && !profile.hidden) setProfile(false);
        });
    }

    async function postJSON(body) {
        const response = await fetch("messages_api.php", {
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

    if (form) {
        const textarea = document.getElementById("msgReply");
        const button = document.getElementById("msgSend");
        const about = document.getElementById("msgAbout");
        const hint = document.getElementById("msgAboutHint");

        function updateHint() {
            hint.textContent = about.value ? hint.dataset.report : hint.dataset.general;
            hint.classList.toggle("is-warning", !about.value);
        }
        about.addEventListener("change", updateHint);
        updateHint();

        // Reply to a specific message (like Messenger)
        const replying = document.getElementById("msgReplying");
        let replyTo = "";

        function startReply(row) {
            replyTo = row.dataset.key;
            replying.querySelector("[data-replying-name]").textContent = row.dataset.name;
            replying.querySelector("[data-replying-text]").textContent = row.dataset.preview;
            replying.hidden = false;
            // A reply about a report goes to that same report.
            about.value = row.dataset.report || "";
            updateHint();
            textarea.focus();
        }

        function cancelReply() {
            replyTo = "";
            replying.hidden = true;
        }

        replying.querySelector("[data-replying-cancel]").addEventListener("click", () => {
            cancelReply();
            textarea.focus();
        });

        textarea.addEventListener("keydown", (event) => {
            if (event.key === "Escape" && replyTo) cancelReply();
        });

        document.addEventListener("click", async (event) => {
            if (!(event.target instanceof Element)) return;

            const replyButton = event.target.closest("[data-msg-reply]");
            if (replyButton) {
                startReply(replyButton.closest(".msg-bubble-row"));
                return;
            }

            const deleteButton = event.target.closest("[data-msg-delete]");
            if (deleteButton) {
                const row = deleteButton.closest(".msg-bubble-row");
                const mine = row.classList.contains("is-mine");
                const ok = await CS.confirm({
                    title: "Delete this message?",
                    message: mine
                        ? "Your message will be removed for everyone, also in the resident's CitiSense app. This cannot be undone."
                        : "This message from " + row.dataset.name + " will be removed for everyone, also in their CitiSense app. This cannot be undone.",
                    confirmText: "Yes, Delete",
                    danger: true,
                    icon: "bi-trash"
                });
                if (!ok) return;

                deleteButton.disabled = true;
                try {
                    const data = await postJSON({ action: "delete", userID: form.dataset.user, key: row.dataset.key });
                    const key = row.dataset.key;
                    if (replyTo === key) cancelReply();
                    row.remove();
                    // Replies that quoted it now say the original was deleted.
                    document.querySelectorAll('[data-jump="' + key + '"]').forEach((quote) => {
                        const note = document.createElement("span");
                        note.className = "msg-quote is-missing";
                        note.innerHTML = '<i class="bi bi-reply" aria-hidden="true"></i> Original message was deleted';
                        quote.replaceWith(note);
                    });
                    CS.toast(data.message, "success");
                } catch (error) {
                    deleteButton.disabled = false;
                    CS.toast(error.message, "error");
                }
                return;
            }

            // Click a quote to jump to the original message.
            const jump = event.target.closest("[data-jump]");
            if (jump) {
                const target = document.getElementById("msg-" + jump.dataset.jump);
                if (target) {
                    event.preventDefault();
                    target.scrollIntoView({ block: "center", behavior: "smooth" });
                    target.classList.remove("is-highlight");
                    void target.offsetWidth;
                    target.classList.add("is-highlight");
                }
            }
        });

        // Ctrl/Cmd + Enter sends; plain Enter makes a new line.
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
                CS.toast("Please type a message first.", "error");
                textarea.focus();
                return;
            }

            button.disabled = true;
            button.textContent = "Sending…";

            try {
                const data = await postJSON({
                    action: "send",
                    userID: form.dataset.user,
                    reportID: about.value,
                    replyTo,
                    message
                });
                CS.flashAfterReload(data.message, "success");
                // Keep the chosen report selected after the reload.
                const url = new URL(window.location.href);
                url.searchParams.set("report", about.value || "general");
                window.location.replace(url.toString());
            } catch (error) {
                CS.toast(error.message, "error");
                button.disabled = false;
                button.innerHTML = '<i class="bi bi-send" aria-hidden="true"></i> Send';
            }
        });
    }
})();

/* Live updates: every 15 seconds (and when the tab becomes visible again) the
   page quietly checks for new messages and updates the conversation and the
   list, without reloading and without touching what you are typing. */
(function () {
    "use strict";

    const list = document.getElementById("msgMessages");
    const threads = document.querySelector(".msg-thread-list");
    if (!list && !threads) return;

    const keysOf = (root) => Array.from(root.querySelectorAll("[data-key]"), (el) => el.dataset.key).join(",");
    let busy = false;

    async function refresh() {
        if (busy || document.hidden) return;
        busy = true;
        try {
            const response = await fetch(window.location.href, { headers: { Accept: "text/html" }, cache: "no-store" });
            if (!response.ok || response.redirected) return; // e.g. logged out: keep the page as it is
            const doc = new DOMParser().parseFromString(await response.text(), "text/html");

            const freshList = doc.getElementById("msgMessages");
            if (list && freshList && keysOf(freshList) !== keysOf(list)) {
                const atBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 60;
                list.innerHTML = freshList.innerHTML;
                if (atBottom) list.scrollTop = list.scrollHeight;
            }

            const freshThreads = doc.querySelector(".msg-thread-list");
            if (threads && freshThreads && freshThreads.innerHTML !== threads.innerHTML) {
                threads.innerHTML = freshThreads.innerHTML;
            }
        } catch (error) {
            /* Offline for a moment: try again at the next check. */
        } finally {
            busy = false;
        }
    }

    window.setInterval(refresh, 15000);
    document.addEventListener("visibilitychange", () => {
        if (!document.hidden) refresh();
    });
})();
