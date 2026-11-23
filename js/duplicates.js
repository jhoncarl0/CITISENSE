/* ==========================================================================
   CitiSense Admin — Possible Duplicates page
   --------------------------------------------------------------------------
   Mark as Duplicate  -> reject_report.php (Pending) or
                         update_report_status.php (In Progress / Overdue),
                         then duplicate_review.php records the decision.
   Not a Duplicate    -> duplicate_review.php (pair no longer suggested).
   ========================================================================== */

(function () {
    "use strict";

    const CS = window.CitiSense;
    let groups = {};
    try {
        groups = JSON.parse(document.getElementById("duplicateGroups").textContent || "{}");
    } catch (error) {
        groups = {};
    }

    const form = document.getElementById("duplicateForm");
    const select = document.getElementById("duplicateOf");
    const reason = document.getElementById("duplicateReason");
    let current = null; // { reportID, group }

    async function postJSON(url, body) {
        const response = await fetch(url, {
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

    function messageFor(otherID) {
        return "Thank you for reporting! This problem was already reported (report " + otherID +
            ") and the barangay is handling it there.";
    }

    function openMark(reportID, groupID) {
        const group = groups[groupID];
        if (!group) return;
        if (reportID === group.main) {
            CS.toast("Report " + reportID + " is the suggested main report, so it cannot be marked as a duplicate.", "error");
            return;
        }

        current = { reportID, group };
        const others = group.ids.filter((id) => id !== reportID);
        const preferred = reportID === group.main ? others[0] : group.main;

        select.replaceChildren(...others.map((id) => {
            const option = document.createElement("option");
            option.value = id;
            option.textContent = "Report " + id + (id === group.main ? " (main report)" : "");
            option.selected = id === preferred;
            return option;
        }));

        document.getElementById("duplicateTitle").textContent = "Mark report " + reportID + " as a duplicate?";
        document.getElementById("duplicateText").textContent =
            "Report " + reportID + " will be closed, and its resident will be told the problem was already reported.";
        reason.value = messageFor(preferred);
        CS.openModal("duplicateModal");
    }

    // Keep the message in step with the chosen report, unless it was edited.
    select.addEventListener("change", () => {
        if (/^Thank you for reporting! This problem was already reported \(report [^)]+\)/.test(reason.value)) {
            reason.value = messageFor(select.value);
        }
    });

    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (!current) return;

        const text = reason.value.trim();
        const button = document.getElementById("duplicateSubmit");
        const status = current.group.status[current.reportID];

        if (text.length < 5) {
            CS.toast("Please write a short message for the resident.", "error");
            reason.focus();
            return;
        }

        button.disabled = true;
        button.textContent = "Saving…";

        try {
            // The server checks first (e.g. the main report cannot be marked); nothing is closed if it refuses.
            await postJSON("duplicate_review.php", {
                action: "check_duplicate",
                reportID: current.reportID,
                otherIDs: [select.value]
            });

            if (status === "STAT001") {
                await postJSON("reject_report.php", { reportID: current.reportID, reason: text });
            } else {
                await postJSON("update_report_status.php", { reportID: current.reportID, statusID: "rejected", remarks: text });
            }

            await postJSON("duplicate_review.php", {
                action: "duplicate",
                reportID: current.reportID,
                otherIDs: [select.value]
            }).catch(() => null); // the report is already closed; the log is optional

            CS.flashAfterReload("Report " + current.reportID + " was closed as a duplicate of report " + select.value + ".", "success");
            window.location.reload();
        } catch (error) {
            CS.toast(error.message, "error");
            button.disabled = false;
            button.innerHTML = '<i class="bi bi-files" aria-hidden="true"></i> Mark as Duplicate';
        }
    });

    async function notDuplicate(reportID, groupID) {
        const group = groups[groupID];
        if (!group) return;

        const others = group.ids.filter((id) => id !== reportID);
        const ok = await CS.confirm({
            title: "Not a duplicate?",
            message: "Report " + reportID + " stays open and will no longer be suggested together with " +
                (others.length === 1 ? "report " + others[0] : "these " + others.length + " reports") + ".",
            confirmText: "Yes, Not a Duplicate",
            icon: "bi-check2-circle"
        });
        if (!ok) return;

        try {
            const data = await postJSON("duplicate_review.php", { action: "not_duplicate", reportID, otherIDs: others });
            CS.flashAfterReload(data.message, "success");
            window.location.reload();
        } catch (error) {
            CS.toast(error.message, "error");
        }
    }

    document.addEventListener("click", (event) => {
        if (!(event.target instanceof Element)) return;

        const mark = event.target.closest("[data-mark-duplicate]");
        if (mark) return openMark(mark.dataset.markDuplicate, mark.dataset.group);

        const keep = event.target.closest("[data-not-duplicate]");
        if (keep) return notDuplicate(keep.dataset.notDuplicate, keep.dataset.group);
    });
})();
