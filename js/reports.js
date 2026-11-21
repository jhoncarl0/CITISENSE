/* ==========================================================================
   CitiSense Admin — Reports page
   --------------------------------------------------------------------------
   View details · Accept · Reject · Mark as Resolved · Set target date

   Endpoints (all existing except reject_report.php):
     update_report_status.php    Accept (-> In Progress), Resolve (-> Resolved)
     update_report_timeline.php  Target completion date
     reject_report.php           Reject a Pending report with a reason
   ========================================================================== */

(function () {
    "use strict";

    const CS = window.CitiSense;
    const PENDING = "STAT001";
    const IN_PROGRESS = "STAT002";
    const RESOLVED = "STAT003";
    const OVERDUE = "STAT004";

    let reports = {};
    try {
        reports = JSON.parse(document.getElementById("reportPayload").textContent || "{}") || {};
    } catch (error) {
        console.error("Reports: could not read report data.", error);
    }

    const drawer = document.getElementById("reportDrawer");
    const overlay = document.getElementById("reportDrawerOverlay");
    const drawerTitle = document.getElementById("reportDrawerTitle");
    const drawerBody = document.getElementById("reportDrawerContent");
    const drawerActions = document.getElementById("reportDrawerActions");

    let activeReport = null;
    let lastTrigger = null;

    /* ------------------------------------------------------------ helpers */

    function el(tag, attrs, ...children) {
        const node = document.createElement(tag);
        Object.entries(attrs || {}).forEach(([key, value]) => {
            if (value === null || value === undefined || value === false) return;
            if (key === "class") node.className = value;
            else if (key === "text") node.textContent = value;
            else if (key.startsWith("on")) node.addEventListener(key.slice(2), value);
            else node.setAttribute(key, value === true ? "" : value);
        });
        children.flat().forEach((child) => {
            if (child === null || child === undefined || child === false) return;
            node.append(child instanceof Node ? child : document.createTextNode(String(child)));
        });
        return node;
    }

    const icon = (name) => el("i", { class: "bi " + name, "aria-hidden": "true" });

    function badge(statusName) {
        const slug = String(statusName).toLowerCase().replace(/[^a-z0-9]+/g, "-");
        const icons = {
            pending: "bi-hourglass-split", "in-progress": "bi-tools", resolved: "bi-check-circle-fill",
            overdue: "bi-exclamation-triangle-fill", rejected: "bi-x-circle-fill"
        };
        const known = ["pending", "in-progress", "resolved", "overdue", "rejected"];
        return el("span", { class: "cs-badge has-icon cs-badge-" + (known.includes(slug) ? slug : "neutral") },
            icon(icons[slug] || "bi-circle"), statusName);
    }

    function othersText(report) {
        const others = report.cycleSize - 1;
        return others > 0
            ? " " + others + " other " + (others === 1 ? "resident" : "residents") + " reported the same problem and will be updated too."
            : "";
    }

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

    function busy(button, on, text) {
        if (on) {
            button.dataset.label = button.innerHTML;
            button.disabled = true;
            button.textContent = text || "Saving…";
        } else {
            button.disabled = false;
            if (button.dataset.label) button.innerHTML = button.dataset.label;
        }
    }

    function done(message) {
        CS.flashAfterReload(message, "success");
        window.location.reload();
    }

    /* ------------------------------------------------------------- drawer */

    function openDrawer(trigger) {
        lastTrigger = trigger || document.activeElement;
        drawer.classList.add("is-open");
        overlay.classList.add("is-open");
        document.body.classList.add("cs-no-scroll");
        setTimeout(() => drawer.focus({ preventScroll: true }), 50);
    }

    function closeDrawer() {
        drawer.classList.remove("is-open");
        overlay.classList.remove("is-open");
        document.body.classList.remove("cs-no-scroll");
        if (lastTrigger && lastTrigger.isConnected) lastTrigger.focus();
    }

    document.getElementById("closeReportDrawer").addEventListener("click", closeDrawer);
    overlay.addEventListener("click", closeDrawer);
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && drawer.classList.contains("is-open") && !document.querySelector(".cs-modal.is-open")) {
            closeDrawer();
        }
    });

    function row(label, value) {
        return el("div", { class: "rp-row" },
            el("dt", { text: label }),
            el("dd", {}, value || el("span", { class: "cs-subtle", text: "Not provided" }))
        );
    }

    function isRejected(report) {
        return String(report.status).toLowerCase() === "rejected";
    }

    function actionButtons(report, inDrawer) {
        const buttons = [];
        const size = inDrawer ? "" : " cs-btn-sm";

        if (report.statusID === PENDING) {
            if (report.isOriginal || report.originalStatusID === PENDING) {
                buttons.push(el("button", { type: "button", class: "cs-btn cs-btn-primary" + size, "data-accept": report.id }, icon("bi-check-lg"), "Accept"));
            }
            buttons.push(el("button", { type: "button", class: "cs-btn cs-btn-danger-outline" + size, "data-reject": report.id }, icon("bi-x-lg"), "Reject"));
        } else if ((report.statusID === IN_PROGRESS || report.statusID === OVERDUE) && report.isOriginal) {
            buttons.push(el("button", { type: "button", class: "cs-btn cs-btn-primary" + size, "data-resolve": report.id }, icon("bi-check2-all"), "Mark as Resolved"));
            buttons.push(el("button", { type: "button", class: "cs-btn cs-btn-secondary" + size, "data-change-status": report.id }, icon("bi-arrow-left-right"), "Change Status"));
        } else if (isRejected(report)) {
            buttons.push(el("button", { type: "button", class: "cs-btn cs-btn-secondary" + size, "data-restore": report.id }, icon("bi-arrow-counterclockwise"), "Restore to New"));
        }

        return buttons;
    }

    function showDetails(id, trigger) {
        const report = reports[id];
        if (!report) {
            CS.toast("This report could not be opened. Please refresh the page.", "error");
            return;
        }
        activeReport = report;
        drawerTitle.textContent = "Report " + report.id;

        const photo = report.images.length
            ? el("a", { href: report.images[0], target: "_blank", rel: "noopener", class: "rp-photo", title: "Open full-size photo" },
                el("img", { src: report.images[0], alt: "Photo from the resident" }),
                el("span", { class: "rp-photo-hint" }, icon("bi-arrows-fullscreen"), "Open full size"))
            : el("div", { class: "rp-no-photo" }, icon("bi-image"), "No photo was attached.");

        const extraPhotos = report.images.length > 1
            ? el("p", { class: "cs-subtle rp-more-photos" },
                report.images.slice(1).map((src, i) => el("a", { href: src, target: "_blank", rel: "noopener" }, "Photo " + (i + 2))))
            : null;

        let notice = null;
        if (!report.isOriginal) {
            const original = reports[report.originalID];
            notice = el("div", { class: "cs-alert cs-alert-info" }, icon("bi-link-45deg"),
                el("div", { class: "cs-alert-body" },
                    el("strong", { class: "cs-alert-title", text: "Same problem as report " + report.originalID }),
                    report.statusID === PENDING && report.originalStatusID !== PENDING
                        ? "Report " + report.originalID + " is already " + report.originalStatus + ". You can reject this repeat report if it is not needed."
                        : "Its status changes together with report " + report.originalID + ".",
                    original ? el("button", { type: "button", class: "rp-link-btn", "data-view": report.originalID }, "Open report " + report.originalID) : null
                ));
        } else if (report.statusID === RESOLVED) {
            notice = el("div", { class: "cs-alert cs-alert-success" }, icon("bi-check-circle-fill"),
                el("div", { class: "cs-alert-body" }, "This report is resolved. No more action is needed."));
        } else if (isRejected(report)) {
            notice = el("div", { class: "cs-alert cs-alert-error" }, icon("bi-x-circle-fill"),
                el("div", { class: "cs-alert-body" }, "This report was rejected. See the reason in the history below. If this was a mistake, choose Restore to New."));
        }

        /* Target date (accepted first reports only) */
        let timelineBlock = null;
        if (report.isOriginal && (report.statusID === IN_PROGRESS || report.statusID === OVERDUE)) {
            const input = el("input", { type: "datetime-local", id: "rpTimeline", class: "cs-input", value: report.timelineInput });
            const save = el("button", { type: "submit", class: "cs-btn cs-btn-secondary" }, "Save Date");
            const form = el("form", { class: "rp-timeline" },
                el("label", { class: "cs-label", for: "rpTimeline", text: "Expected completion date" }),
                el("div", { class: "rp-timeline-row" }, input, save),
                report.statusID === OVERDUE
                    ? el("span", { class: "cs-hint", text: "This report is past its date. Saving a new date moves it back to In Progress." })
                    : null
            );
            form.addEventListener("submit", async (event) => {
                event.preventDefault();
                if (!input.value) {
                    CS.toast("Please choose a date and time.", "error");
                    return;
                }
                busy(save, true);
                try {
                    const data = await postJSON("update_report_timeline.php", { reportID: report.id, timeline: input.value });
                    done(data.message || "Date saved.");
                } catch (error) {
                    CS.toast(error.message, "error");
                    busy(save, false);
                }
            });
            timelineBlock = el("section", { class: "rp-section" }, form);
        }

        const history = el("ol", { class: "rp-history" },
            el("li", {}, el("strong", { text: "Submitted" }), el("span", { text: report.date })),
            report.history.map((h) => el("li", {},
                badge(h.status),
                el("span", { text: h.date + (h.by ? " · " + h.by : "") }),
                h.remarks ? el("p", { text: "“" + h.remarks + "”" }) : null
            ))
        );

        const duplicateNote = report.duplicateGroup
            ? el("div", { class: "cs-alert cs-alert-warning" }, icon("bi-files"),
                el("div", { class: "cs-alert-body" },
                    el("strong", { class: "cs-alert-title", text: "This may be a duplicate" }),
                    "It looks like the same problem as report " + report.duplicateOthers.join(", ") + ". ",
                    el("a", { href: "possible_duplicates.php#group-" + report.duplicateGroup }, "Compare them side by side")
                ))
            : null;

        drawerBody.replaceChildren(el("div", { class: "rp-details" },
            el("div", { class: "rp-details-head" },
                badge(report.status),
                el("h3", { text: report.title || report.category }),
                el("p", { class: "rp-line" }, icon("bi-geo-alt"), report.address || "Location not provided")
            ),
            notice,
            duplicateNote,
            photo,
            extraPhotos,
            el("section", { class: "rp-section" },
                el("h4", { text: "What the resident said" }),
                el("p", { class: "rp-description", text: report.description || "No description was given." })
            ),
            timelineBlock,
            el("section", { class: "rp-section rp-message-box" },
                el("div", {},
                    el("h4", { text: "Talk to the resident" }),
                    el("p", { class: "cs-subtle", text: "Ask for more details or give an update. The resident sees it in the CitiSense app." })
                ),
                el("a", { class: "cs-btn cs-btn-secondary cs-btn-sm", href: "messages.php?type=report&id=" + encodeURIComponent(report.id) },
                    icon("bi-chat-dots"), "Open Messages")
            ),
            el("section", { class: "rp-section" },
                el("dl", { class: "rp-rows" },
                    row("Type of issue", report.category),
                    row("Submitted", report.date),
                    report.timeline ? row("Expected completion", report.timeline) : null,
                    row("Resident", report.resident),
                    row("Email", report.email ? el("a", { href: "mailto:" + report.email, text: report.email }) : null),
                    row("Contact number", report.contact ? el("a", { href: "tel:" + report.contact.replace(/[^0-9+]/g, ""), text: report.contact }) : null)
                )
            ),
            el("section", { class: "rp-section" }, el("h4", { text: "History" }), history)
        ));

        const buttons = actionButtons(report, true);
        drawerActions.replaceChildren(...buttons);
        drawerActions.hidden = buttons.length === 0;

        drawerBody.scrollTop = 0;
        if (!drawer.classList.contains("is-open")) openDrawer(trigger);
    }

    /* ------------------------------------------------------------ actions */

    const acceptForm = document.getElementById("acceptForm");
    const rejectForm = document.getElementById("rejectForm");
    const resolveForm = document.getElementById("resolveForm");

    function openAccept(report) {
        activeReport = report;
        const target = report.isOriginal ? report.id : report.originalID;
        document.getElementById("acceptTitle").textContent = "Accept report " + target + "?";
        document.getElementById("acceptText").textContent =
            "It will move to In Progress and the resident will be notified." + othersText(reports[target] || report);
        acceptForm.reset();
        CS.openModal("acceptModal");
    }

    function openReject(report) {
        activeReport = report;
        document.getElementById("rejectTitle").textContent = "Reject report " + report.id + "?";
        rejectForm.reset();
        CS.openModal("rejectModal");
    }

    function openResolve(report) {
        activeReport = report;
        document.getElementById("resolveTitle").textContent = "Mark report " + report.id + " as resolved?";
        document.getElementById("resolveText").textContent =
            "Only do this when the problem is fixed. This closes the report and notifies the resident." + othersText(report);
        resolveForm.reset();
        CS.openModal("resolveModal");
    }

    acceptForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        const report = activeReport;
        const target = report.isOriginal ? report.id : report.originalID;
        const button = document.getElementById("acceptSubmit");
        const timeline = document.getElementById("acceptTimeline").value;

        busy(button, true);
        try {
            await postJSON("update_report_status.php", {
                reportID: target,
                statusID: IN_PROGRESS,
                remarks: document.getElementById("acceptMessage").value.trim()
            });
            if (timeline) {
                await postJSON("update_report_timeline.php", { reportID: target, timeline });
            }
            done("Report " + target + " was accepted." + (timeline ? " The expected completion date was saved." : ""));
        } catch (error) {
            CS.toast(error.message, "error");
            busy(button, false);
        }
    });

    // Picking a ready-made reason fills in the text box.
    rejectForm.addEventListener("change", (event) => {
        if (event.target.name === "quickReason") {
            document.getElementById("rejectReason").value = event.target.value;
        }
    });

    rejectForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        const reasonBox = document.getElementById("rejectReason");
        const reason = reasonBox.value.trim();
        const button = document.getElementById("rejectSubmit");

        if (reason.length < 5) {
            CS.toast("Please choose or write a reason for the resident.", "error");
            reasonBox.focus();
            return;
        }

        busy(button, true);
        try {
            const data = await postJSON("reject_report.php", { reportID: activeReport.id, reason });
            done(data.message || "The report was rejected.");
        } catch (error) {
            CS.toast(error.message, "error");
            busy(button, false);
        }
    });

    resolveForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        const button = document.getElementById("resolveSubmit");
        busy(button, true);
        try {
            await postJSON("update_report_status.php", {
                reportID: activeReport.id,
                statusID: RESOLVED,
                remarks: document.getElementById("resolveMessage").value.trim()
            });
            done("Report " + activeReport.id + " was marked as resolved. The resident has been notified.");
        } catch (error) {
            CS.toast(error.message, "error");
            busy(button, false);
        }
    });

    /* Change the status of an accepted report (In Progress / Overdue). */
    const statusForm = document.getElementById("statusForm");
    const statusMessage = document.getElementById("statusMessage");
    const statusReasons = document.getElementById("statusReasons");
    const statusLabel = document.getElementById("statusMessageLabel");

    function openChangeStatus(report) {
        activeReport = report;
        statusForm.reset();
        statusReasons.hidden = true;
        document.getElementById("statusTitle").textContent = "Change the status of report " + report.id;
        document.getElementById("statusText").textContent =
            "It is currently " + report.status + ". Choose the new status; the resident will be notified." + othersText(report);
        updateStatusForm();
        CS.openModal("statusModal");
    }

    function updateStatusForm() {
        const chosen = statusForm.querySelector("input[name=newStatus]:checked");
        const rejecting = chosen && chosen.value === "rejected";
        statusReasons.hidden = !rejecting;
        statusLabel.innerHTML = rejecting
            ? "Reason sent to the resident <span class=\"cs-subtle\">(required)</span>"
            : "Message to the resident <span class=\"cs-subtle\">(optional)</span>";
        statusMessage.placeholder = rejecting
            ? "Choose a quick reason above or write your own."
            : "Example: Our team has finished the repair.";
        const submit = document.getElementById("statusSubmit");
        submit.className = "cs-btn " + (rejecting ? "cs-btn-danger" : "cs-btn-primary");
    }

    statusForm.addEventListener("change", updateStatusForm);

    statusForm.addEventListener("click", (event) => {
        const chip = event.target instanceof Element && event.target.closest("[data-reason]");
        if (chip) statusMessage.value = chip.dataset.reason;
    });

    statusForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        const chosen = statusForm.querySelector("input[name=newStatus]:checked");
        const button = document.getElementById("statusSubmit");
        const remarks = statusMessage.value.trim();

        if (!chosen) {
            CS.toast("Please choose the new status.", "error");
            return;
        }
        if (chosen.value === "rejected" && remarks.length < 5) {
            CS.toast("Please choose or write a reason for the resident.", "error");
            statusMessage.focus();
            return;
        }

        busy(button, true);
        try {
            const data = await postJSON("update_report_status.php", {
                reportID: activeReport.id,
                statusID: chosen.value,
                remarks
            });
            done(data.message || "The status was updated.");
        } catch (error) {
            CS.toast(error.message, "error");
            busy(button, false);
        }
    });

    async function restoreReport(report) {
        const ok = await CS.confirm({
            title: "Restore report " + report.id + "?",
            message: "It will go back to the New tab as Pending so you can review it again. The resident will be notified.",
            confirmText: "Yes, Restore",
            icon: "bi-arrow-counterclockwise"
        });
        if (!ok) return;

        try {
            const data = await postJSON("update_report_status.php", { reportID: report.id, statusID: PENDING });
            done(data.message || "The report was restored.");
        } catch (error) {
            CS.toast(error.message, "error");
        }
    }

    document.addEventListener("click", (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;

        const change = target.closest("[data-change-status]");
        if (change && reports[change.dataset.changeStatus]) return openChangeStatus(reports[change.dataset.changeStatus]);

        const restore = target.closest("[data-restore]");
        if (restore && reports[restore.dataset.restore]) return restoreReport(reports[restore.dataset.restore]);

        const view = target.closest("[data-view]");
        if (view) return showDetails(view.dataset.view, view);

        const accept = target.closest("[data-accept]");
        if (accept && reports[accept.dataset.accept]) return openAccept(reports[accept.dataset.accept]);

        const reject = target.closest("[data-reject]");
        if (reject && reports[reject.dataset.reject]) return openReject(reports[reject.dataset.reject]);

        const resolve = target.closest("[data-resolve]");
        if (resolve && reports[resolve.dataset.resolve]) return openResolve(reports[resolve.dataset.resolve]);
    });

    /* ----------------------------------------------------- download form */

    const downloadForm = document.getElementById("downloadReportForm");
    if (downloadForm) {
        downloadForm.addEventListener("submit", () => {
            setTimeout(() => {
                CS.closeModal("downloadReportModal");
                CS.toast("Your file is being prepared. It will download shortly.", "info");
            }, 150);
        });
    }
})();
