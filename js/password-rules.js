/* ==========================================================================
   CitiSense Admin — password rules in the browser
   --------------------------------------------------------------------------
   Same rules as admin/partial/passwords.php (the server checks them again).
   Usage: <input type="password" data-password-rules data-email="…">
          optional data-confirm="idOfConfirmField"
   Shows a live checklist under the field and stops the form from being sent
   while a rule is not met.
   ========================================================================== */

(function () {
    "use strict";

    const MIN = 10;
    const MAX = 64;

    const RULES = [
        { id: "chars", text: "Only letters, numbers and keyboard symbols (no spaces or emojis)", test: (v) => v !== "" && /^[\x21-\x7E]+$/.test(v) },
        { id: "length", text: MIN + " to " + MAX + " characters", test: (v) => v.length >= MIN && v.length <= MAX },
        { id: "upper", text: "An uppercase letter (A–Z)", test: (v) => /[A-Z]/.test(v) },
        { id: "lower", text: "A lowercase letter (a–z)", test: (v) => /[a-z]/.test(v) },
        { id: "number", text: "A number (0–9)", test: (v) => /[0-9]/.test(v) },
        { id: "symbol", text: "A symbol, e.g. ! @ # $ % *", test: (v) => /[\x21-\x2F\x3A-\x40\x5B-\x60\x7B-\x7E]/.test(v) }
    ];

    function emailRule(email) {
        const name = String(email || "").split("@")[0].toLowerCase();
        if (name.length < 3) return null;
        return { id: "email", text: "Does not contain your email name", test: (v) => v !== "" && !v.toLowerCase().includes(name) };
    }

    function setup(input) {
        const rules = RULES.slice();
        const extra = emailRule(input.dataset.email);
        if (extra) rules.push(extra);

        const list = document.createElement("ul");
        list.className = "cs-pw-rules";
        list.id = input.id + "Rules";
        list.setAttribute("aria-live", "polite");
        const items = rules.map((rule) => {
            const item = document.createElement("li");
            item.innerHTML = '<i class="bi bi-circle" aria-hidden="true"></i><span></span>';
            item.querySelector("span").textContent = rule.text;
            list.appendChild(item);
            return item;
        });

        const describedBy = (input.getAttribute("aria-describedby") || "").trim();
        input.setAttribute("aria-describedby", (describedBy + " " + list.id).trim());
        (input.closest(".cs-field") || input.parentElement).appendChild(list);

        const confirm = input.dataset.confirm ? document.getElementById(input.dataset.confirm) : null;

        function check() {
            const value = input.value;
            let ok = true;
            rules.forEach((rule, i) => {
                const passed = rule.test(value);
                ok = ok && passed;
                items[i].classList.toggle("is-ok", passed);
                items[i].querySelector("i").className = "bi " + (passed ? "bi-check-circle-fill" : "bi-circle");
            });
            input.setCustomValidity(ok ? "" : "Please follow the password rules listed below the field.");
            if (confirm) {
                confirm.setCustomValidity(confirm.value !== "" && confirm.value !== value ? "The passwords do not match." : "");
            }
            return ok;
        }

        input.addEventListener("input", check);
        if (confirm) confirm.addEventListener("input", check);
        check();
    }

    document.querySelectorAll("input[data-password-rules]").forEach(setup);
})();
