/* ==========================================================================
   CitiSense Admin — Messages
   Send replies to residents about their reports and keep the view scrolled
   to the newest message.
   ========================================================================== */

(function () {
    "use strict";

    const CS = window.CitiSense;
    const list = document.getElementById("msgMessages");
    const form = document.getElementById("msgReplyForm");

    if (list) list.scrollTop = list.scrollHeight;

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
                const data = await postJSON({ action: "reply", type: form.dataset.type, id: form.dataset.id, message });
                CS.flashAfterReload(data.message, "success");
                window.location.reload();
            } catch (error) {
                CS.toast(error.message, "error");
                button.disabled = false;
                button.innerHTML = '<i class="bi bi-send" aria-hidden="true"></i> Send';
            }
        });
    }
})();
