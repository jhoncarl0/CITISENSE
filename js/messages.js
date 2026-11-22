/* ==========================================================================
   CitiSense Admin — Messages
   One conversation per resident: send a general message or one about a
   report, show / hide the resident's profile, keep the view scrolled to the
   newest message.
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
        }
        about.addEventListener("change", updateHint);
        updateHint();

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
                    message
                });
                CS.flashAfterReload(data.message, "success");
                // Keep the chosen report selected after the reload.
                const url = new URL(window.location.href);
                if (about.value) url.searchParams.set("report", about.value);
                else url.searchParams.delete("report");
                window.location.replace(url.toString());
            } catch (error) {
                CS.toast(error.message, "error");
                button.disabled = false;
                button.innerHTML = '<i class="bi bi-send" aria-hidden="true"></i> Send';
            }
        });
    }
})();
