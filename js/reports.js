/* ==========================================================================
   CitiSense Admin — report details panel ("View") and report actions
   --------------------------------------------------------------------------
   View details · Accept · Reject · Mark as Resolved · Set target date

   Used on the Reports page and on every page that opens a report in place
   (Possible Duplicates, Resident Feedback, Issue Map, Dashboard, Messages):
   any element with data-view="REPORTID" opens that report here. Reports that
   are not on the page are loaded from report_details.php, so the
   administrator never leaves the current page, tab, filters or page number.

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
    const payloadElement = document.getElementById("reportPayload");
    if (payloadElement) {
        try {
            reports = JSON.parse(payloadElement.textContent || "{}") || {};
        } catch (error) {
            console.error("Reports: could not read report data.", error);
        }
    }

    const drawerCheck = document.getElementById("reportDrawer");
    if (!drawerCheck) return; // this page has no details panel

    const drawer = document.getElementById("reportDrawer");
    const overlay = document.getElementById("reportDrawerOverlay");
    const drawerTitle = document.getElementById("reportDrawerTitle");
    const drawerBody = document.getElementById("reportDrawerContent");
    const drawerActions = document.getElementById("reportDrawerActions");

    let activeReport = null;
    let lastTrigger = null;
    let loadingID = "";

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
        const others = report.cycleSize - 1 + (report.linkedCount || 0);
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

    /** Today at 00:00 in the format of a datetime-local input. */
    function todayStart() {
        const now = new Date();
        const pad = (n) => String(n).padStart(2, "0");
        return now.getFullYear() + "-" + pad(now.getMonth() + 1) + "-" + pad(now.getDate()) + "T00:00";
    }

    function done(message) {
        CS.flashAfterReload(message, "success");
        window.location.reload();
    }

    /* ------------------------------------------------------------- drawer */

    /* Remember the open report in the address (?open=ID) without reloading,
       so a refresh, or the reload after an action, shows the same report. */
    function rememberOpen(id) {
        const url = new URL(window.location.href);
        if (id) url.searchParams.set("open", id);
        else url.searchParams.delete("open");
        if (url.toString() !== window.location.href) history.replaceState(history.state, "", url.toString());
    }

    function openDrawer(trigger) {
        lastTrigger = trigger || document.activeElement;
        drawer.classList.add("is-open");
        overlay.classList.add("is-open");
        document.body.classList.add("cs-no-scroll");
        setTimeout(() => drawer.focus({ preventScroll: true }), 50);
    }

    function closeDrawer() {
        // Forget ?open=ID, so a refresh shows the page as it was.
        rememberOpen("");
        loadingID = "";
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

    /* Icons per type of issue (same idea as csCategoryIcon() in PHP). */
    function categoryIcon(category) {
        const name = String(category || "").toLowerCase();
        if (name.includes("waste") || name.includes("garbage") || name.includes("trash")) return "bi-trash3";
        if (name.includes("light")) return "bi-lightbulb";
        if (name.includes("drain") || name.includes("flood") || name.includes("water")) return "bi-droplet";
        if (name.includes("road") || name.includes("pothole")) return "bi-cone-striped";
        if (name.includes("tree") || name.includes("vegetation")) return "bi-tree";
        return "bi-exclamation-diamond";
    }

    /* Small map with a pin, like the location preview in the app. */
    let detailMap = null;
    function showMap(report, hasPoint) {
        if (detailMap) {
            detailMap.remove();
            detailMap = null;
        }
        const box = document.getElementById("rpMap");
        if (!hasPoint || !box) return;
        if (!window.L) {
            box.classList.add("rp-map-empty");
            box.textContent = "The map could not be loaded. Use “Open in Google Maps” below.";
            return;
        }

        const point = [report.lat, report.lng];
        detailMap = window.L.map(box, { scrollWheelZoom: false, attributionControl: true }).setView(point, 17);
        window.L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
            maxZoom: 19,
            attribution: "&copy; OpenStreetMap contributors"
        }).addTo(detailMap);
        window.L.marker(point, {
            icon: window.L.divIcon({
                className: "rp-map-pin",
                html: '<i class="bi bi-geo-alt-fill" aria-hidden="true"></i>',
                iconSize: [40, 40],
                iconAnchor: [20, 38]
            })
        }).addTo(detailMap);

        // The drawer slides in, so measure the map again once it is visible.
        setTimeout(() => detailMap && detailMap.invalidateSize(), 320);
    }

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

        // A linked duplicate follows its main report: no actions of its own.
        if (report.linkedTo) return buttons;

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

    /* Load a report that is not on this page, then show it. */
    async function loadAndShow(id, trigger) {
        loadingID = id;
        drawerTitle.textContent = "Report " + id;
        drawerBody.replaceChildren(el("div", { class: "rp-loading", role: "status" },
            el("span", { class: "rp-spinner", "aria-hidden": "true" }), "Loading report " + id + "…"));
        drawerActions.replaceChildren();
        drawerActions.hidden = true;
        if (!drawer.classList.contains("is-open")) openDrawer(trigger);

        try {
            const response = await fetch("report_details.php?id=" + encodeURIComponent(id), {
                headers: { Accept: "application/json" },
                credentials: "same-origin"
            });
            let data = null;
            try {
                data = await response.json();
            } catch (error) {
                throw new Error("The server sent an unexpected reply. Please try again.");
            }
            if (!response.ok || !data || !data.success) {
                throw new Error((data && data.message) || "The report could not be loaded. Please try again.");
            }
            Object.assign(reports, data.reports || {});
            if (loadingID !== id) return; // another report was chosen meanwhile
            loadingID = "";
            showDetails(id, trigger);
        } catch (error) {
            if (loadingID !== id) return;
            loadingID = "";
            drawerBody.replaceChildren(el("div", { class: "cs-alert cs-alert-error" }, icon("bi-exclamation-circle-fill"),
                el("div", { class: "cs-alert-body" }, error.message)));
        }
    }

    function showDetails(id, trigger) {
        const report = reports[id];
        if (!report) {
            loadAndShow(id, trigger);
            return;
        }
        loadingID = "";
        rememberOpen(id);
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
        if (report.linkedTo) {
            // Only reports the administrator marked as duplicates get here.
            notice = el("div", { class: "cs-alert cs-alert-info" }, icon("bi-files"),
                el("div", { class: "cs-alert-body" },
                    el("strong", { class: "cs-alert-title", text: "Confirmed duplicate of report " + report.linkedTo }),
                    "An administrator marked this report as a duplicate. Its status always follows report " + report.linkedTo +
                    " automatically, and its resident gets the same updates.",
                    el("button", { type: "button", class: "rp-link-btn", "data-view": report.linkedTo }, "Open main report " + report.linkedTo)
                ));
        } else if (!report.isOriginal) {
            notice = el("div", { class: "cs-alert cs-alert-info" }, icon("bi-geo-alt"),
                el("div", { class: "cs-alert-body" },
                    el("strong", { class: "cs-alert-title", text: "Same place and type of issue as report " + report.originalID }),
                    report.statusID === PENDING && report.originalStatusID !== PENDING
                        ? "Report " + report.originalID + " is already " + report.originalStatus + ". You can reject this repeat report if it is not needed."
                        : "Its status changes together with report " + report.originalID + ".",
                    el("button", { type: "button", class: "rp-link-btn", "data-view": report.originalID }, "Open report " + report.originalID)
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
            // Not earlier than the day the report was accepted.
            const input = el("input", { type: "datetime-local", id: "rpTimeline", class: "cs-input", value: report.timelineInput, min: report.timelineMin });
            const save = el("button", { type: "submit", class: "cs-btn cs-btn-secondary" }, "Save Date");
            const form = el("form", { class: "rp-timeline" },
                el("label", { class: "cs-label", for: "rpTimeline", text: "Expected completion date" }),
                el("div", { class: "rp-timeline-row" }, input, save),
                el("span", { class: "cs-hint", text: "Choose " + report.acceptedOn + " (when the report was accepted) or a later date." }),
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
                if (report.timelineMin && input.value < report.timelineMin) {
                    CS.toast("The expected completion date cannot be earlier than " + report.acceptedOn + ", when the report was accepted.", "error");
                    input.focus();
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

        /* A suggestion from the duplicate check: never shown as confirmed. */
        const duplicateNote = report.duplicateGroup
            ? el("div", { class: "cs-alert cs-alert-warning" }, icon("bi-files"),
                el("div", { class: "cs-alert-body" },
                    el("strong", { class: "cs-alert-title", text: report.isSuggestedMain ? "Suggested main report of possible duplicates" : "This may be a duplicate (not confirmed)" }),
                    (report.isSuggestedMain
                        ? "Reports " + report.duplicateOthers.join(", ") + " may be about the same problem. "
                        : "It looks like the same problem as report " + report.duplicateOthers.join(", ") + ". ") +
                    "Nothing is linked until you review it. ",
                    el("a", { href: "possible_duplicates.php?from=" + encodeURIComponent(report.id) + "#group-" + report.duplicateGroup }, "Compare them side by side")
                ))
            : null;

        /* Main report of confirmed duplicates: suggested until it is accepted. */
        const roleTag = report.role === "main"
            ? el("span", { class: "rp-role-tag is-main" }, icon("bi-star-fill"), "Main report")
            : (report.role === "suggested-main" || report.isSuggestedMain)
                ? el("span", { class: "rp-role-tag is-suggested" }, icon("bi-star"), "Suggested main report")
                : report.role === "duplicate"
                    ? el("span", { class: "rp-role-tag is-duplicate" }, icon("bi-files"), "Duplicate of " + report.linkedTo)
                    : null;

        /* Same layout as the report form in the CitiSense app: numbered sections
           1 Photo · 2 Issue Category · 3 Location (with map) · 4 Description. */
        const section = (number, title, ...content) => el("section", { class: "rp-form-section" },
            el("h4", { class: "rp-form-heading" }, el("span", { class: "rp-form-number", text: number }), title),
            ...content
        );

        const hasPoint = Number.isFinite(report.lat) && Number.isFinite(report.lng);
        const mapBox = hasPoint
            ? el("div", { class: "rp-map", id: "rpMap", role: "img", "aria-label": "Map of the report location" })
            : el("div", { class: "rp-map rp-map-empty" }, icon("bi-geo-alt"), "No map location was saved for this report.");
        const mapsLink = hasPoint
            ? el("a", {
                class: "rp-map-link",
                href: "https://www.google.com/maps/search/?api=1&query=" + report.lat + "," + report.lng,
                target: "_blank",
                rel: "noopener"
            }, icon("bi-box-arrow-up-right"), "Open in Google Maps")
            : null;

        const formCard = el("div", { class: "rp-form-card" },
            el("div", { class: "rp-form-intro" },
                el("div", { class: "rp-form-badges" }, badge(report.status), roleTag),
                el("h3", { text: report.title || report.category }),
                el("p", { class: "cs-subtle", text: "Report " + report.id + " · submitted " + report.date })
            ),
            el("div", { class: "rp-form-body" },
                section("1", "Photo", photo, extraPhotos),
                section("2", "Issue Category",
                    el("div", { class: "rp-field" }, icon(categoryIcon(report.category)), el("strong", { text: report.category }))
                ),
                section("3", "Location",
                    mapBox,
                    el("div", { class: "rp-field" }, icon(hasPoint ? "bi-geo-alt-fill" : "bi-geo-alt"),
                        el("span", { text: report.address || "Location not provided" })),
                    mapsLink
                ),
                section("4", "Description",
                    el("p", { class: "rp-field rp-description", text: report.description || "No description was given." })
                )
            )
        );

        /* Reports marked as duplicates of this one: they follow its status and date. */
        const linked = (report.linkedIDs || []).map((dupID) => reports[dupID]).filter(Boolean);
        const linkedBlock = linked.length
            ? el("section", { class: "rp-section rp-linked" },
                el("h4", { text: "Confirmed duplicate reports (" + linked.length + ")" }),
                el("p", { class: "cs-subtle", text: (report.role === "main"
                    ? "This is the main report. "
                    : "This is the suggested main report; it becomes the main report when it is accepted. ") +
                    "These reports were marked as its duplicates. They are not listed separately on the Reports page, always have the same status and expected completion date, and their residents get the same updates." }),
                el("ul", { class: "rp-linked-list" }, linked.map((dup) => el("li", {},
                    dup.images.length
                        ? el("img", { class: "rp-linked-thumb", src: dup.images[0], alt: "" })
                        : el("span", { class: "rp-linked-thumb" }, icon(categoryIcon(dup.category))),
                    el("div", { class: "rp-linked-text" },
                        el("strong", { text: dup.id + " · " + dup.category }),
                        el("span", { text: dup.date + (dup.resident ? " · " + dup.resident : "") }),
                        dup.address ? el("span", { text: dup.address }) : null
                    ),
                    badge(dup.status),
                    el("button", { type: "button", class: "cs-btn cs-btn-ghost cs-btn-sm", "data-view": dup.id }, "View")
                )))
            )
            : null;

        /* What the resident answered in the app after the report was resolved. */
        const fb = report.feedback;
        const feedbackBlock = fb
            ? el("section", { class: "rp-section rp-feedback" + (fb.fixed ? "" : " is-not-fixed") },
                el("h4", { text: "Resident feedback" }),
                el("div", { class: "rp-feedback-head" },
                    el("span", { class: "rp-feedback-fixed " + (fb.fixed ? "is-yes" : "is-no") },
                        icon(fb.fixed ? "bi-check-circle-fill" : "bi-x-circle-fill"),
                        fb.fixed ? "The resident says it is fixed" : "The resident says it is NOT fixed"),
                    el("span", { class: "rp-feedback-stars", "aria-label": "Rating " + fb.rating + " out of 5" },
                        el("span", { "aria-hidden": "true", text: "★".repeat(fb.rating) + "☆".repeat(5 - fb.rating) }),
                        el("b", { text: " " + fb.rating + "/5" }))
                ),
                fb.comment ? el("blockquote", { class: "rp-feedback-comment", text: "“" + fb.comment + "”" }) : null,
                el("p", { class: "cs-subtle", text: (fb.changed ? "Updated " : "Sent ") + fb.atLabel })
            )
            : null;

        drawerBody.replaceChildren(el("div", { class: "rp-details" },
            notice,
            duplicateNote,
            feedbackBlock,
            formCard,
            linkedBlock,
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
                    report.timeline ? row("Expected completion", report.timeline) : null,
                    row("Resident", report.resident),
                    row("Email", report.email ? el("a", { href: "mailto:" + report.email, text: report.email }) : null),
                    row("Contact number", report.contact ? el("a", { href: "tel:" + report.contact.replace(/[^0-9+]/g, ""), text: report.contact }) : null)
                )
            ),
            el("section", { class: "rp-section" }, el("h4", { text: "History" }), history)
        ));

        showMap(report, hasPoint);

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
        // Accepting happens today, so the expected completion date starts today.
        document.getElementById("acceptTimeline").min = todayStart();
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

        if (timeline && timeline < todayStart()) {
            CS.toast("The expected completion date cannot be earlier than today, when the report is accepted.", "error");
            document.getElementById("acceptTimeline").focus();
            return;
        }

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
        // Back to New only restores the original status (Pending): no message.
        const backToNew = chosen && chosen.value === PENDING;
        statusMessage.closest(".cs-field").hidden = backToNew;
        if (backToNew) statusMessage.value = "";
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
        const remarks = chosen && chosen.value === PENDING ? "" : statusMessage.value.trim();

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
        if (view) {
            // Links keep a normal address (open in a new tab still works);
            // a plain click opens the report here instead.
            if (view.tagName === "A") {
                if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) return;
                event.preventDefault();
            }
            return showDetails(view.dataset.view, view);
        }

        const accept = target.closest("[data-accept]");
        if (accept && reports[accept.dataset.accept]) return openAccept(reports[accept.dataset.accept]);

        const reject = target.closest("[data-reject]");
        if (reject && reports[reject.dataset.reject]) return openReject(reports[reject.dataset.reject]);

        const resolve = target.closest("[data-resolve]");
        if (resolve && reports[resolve.dataset.resolve]) return openResolve(reports[resolve.dataset.resolve]);
    });

    /* Links such as reports.php?search=REP001&open=REP001 (from Possible
       Duplicates, Messages or the Dashboard) open that report straight away. */
    const openID = new URLSearchParams(window.location.search).get("open");
    if (openID && /^[A-Za-z0-9_-]{1,50}$/.test(openID)) {
        // Shown in place (loaded if it is not in this list); the tab stays as it is.
        showDetails(openID, document.querySelector('[data-view="' + CSS.escape(openID) + '"]'));
    }

    /* Tablets: the tab strip scrolls sideways; keep the chosen tab in view. */
    const tabStrip = document.querySelector(".rp-tabs");
    const activeTab = tabStrip && tabStrip.querySelector(".rp-tab.is-active");
    if (activeTab && tabStrip.scrollWidth > tabStrip.clientWidth) {
        tabStrip.scrollLeft = Math.max(0, activeTab.offsetLeft - tabStrip.clientWidth / 2 + activeTab.offsetWidth / 2);
    }

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
