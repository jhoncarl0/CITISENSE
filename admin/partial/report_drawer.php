<?php
/*
 * CitiSense admin — the report details panel ("View") and its action
 * dialogs (Accept, Reject, Mark as Resolved, Change Status).
 *
 * Included by every page that can open a report in place: Reports,
 * Possible Duplicates, Resident Feedback, Issue Map, Dashboard, Messages.
 * The behaviour is in js/reports.js (loaded by report_drawer_scripts.php);
 * details are loaded from report_details.php when they are not on the page.
 * After an action the CURRENT page reloads, so the administrator stays where
 * they were (same tab, filters and page).
 */
?>
<!-- REPORT DETAILS -->
<div class="cs-drawer-backdrop" id="reportDrawerOverlay"></div>
<aside class="cs-drawer" id="reportDrawer" role="dialog" aria-modal="true" aria-labelledby="reportDrawerTitle" tabindex="-1">
    <div class="cs-drawer-header">
        <h2 id="reportDrawerTitle">Report details</h2>
        <button type="button" class="cs-btn cs-btn-ghost cs-btn-sm" id="closeReportDrawer">
            <i class="bi bi-x-lg" aria-hidden="true"></i> Close
        </button>
    </div>
    <div class="cs-drawer-body" id="reportDrawerContent"></div>
    <div class="rp-drawer-actions" id="reportDrawerActions"></div>
</aside>

<!-- ACCEPT -->
<div class="cs-modal" id="acceptModal" role="dialog" aria-modal="true" aria-labelledby="acceptTitle">
    <div class="cs-modal-dialog">
        <form id="acceptForm">
            <div class="cs-modal-body">
                <span class="cs-confirm-icon"><i class="bi bi-check-lg" aria-hidden="true"></i></span>
                <h2 id="acceptTitle">Accept this report?</h2>
                <p class="cs-muted" id="acceptText">Your team will work on this problem. The resident will be notified.</p>

                <div class="cs-field">
                    <label class="cs-label" for="acceptTimeline">When do you expect to fix it? <span class="cs-subtle">(optional)</span></label>
                    <input type="datetime-local" id="acceptTimeline" class="cs-input">
                </div>

                <div class="cs-field">
                    <label class="cs-label" for="acceptMessage">Message to the resident <span class="cs-subtle">(optional)</span></label>
                    <textarea id="acceptMessage" class="cs-textarea" rows="3" maxlength="1000" placeholder="Example: Our team will check the area tomorrow morning."></textarea>
                </div>
            </div>
            <div class="cs-modal-footer">
                <button type="button" class="cs-btn cs-btn-ghost" data-modal-close>Cancel</button>
                <button type="submit" class="cs-btn cs-btn-primary" id="acceptSubmit">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> Accept Report
                </button>
            </div>
        </form>
    </div>
</div>

<!-- REJECT -->
<div class="cs-modal" id="rejectModal" role="dialog" aria-modal="true" aria-labelledby="rejectTitle">
    <div class="cs-modal-dialog">
        <form id="rejectForm">
            <div class="cs-modal-body">
                <span class="cs-confirm-icon is-danger"><i class="bi bi-x-lg" aria-hidden="true"></i></span>
                <h2 id="rejectTitle">Reject this report?</h2>
                <p class="cs-muted" id="rejectText">The resident will be told that the report was not accepted, with your reason.</p>

                <fieldset class="rp-reasons">
                    <legend class="cs-label">Why are you rejecting it?</legend>
                    <?php foreach ([
                        "This problem was already reported.",
                        "The location is outside our barangay.",
                        "There is not enough information or a clear photo.",
                        "This is not a problem the barangay handles.",
                    ] as $reason): ?>
                        <label class="rp-reason">
                            <input type="radio" name="quickReason" value="<?= csEscape($reason) ?>">
                            <span><?= csEscape($reason) ?></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>

                <div class="cs-field">
                    <label class="cs-label" for="rejectReason">Reason sent to the resident</label>
                    <textarea id="rejectReason" class="cs-textarea" rows="3" maxlength="1000" required placeholder="Choose a reason above or write your own."></textarea>
                </div>
            </div>
            <div class="cs-modal-footer">
                <button type="button" class="cs-btn cs-btn-ghost" data-modal-close>Cancel</button>
                <button type="submit" class="cs-btn cs-btn-danger" id="rejectSubmit">
                    <i class="bi bi-x-lg" aria-hidden="true"></i> Reject Report
                </button>
            </div>
        </form>
    </div>
</div>

<!-- RESOLVE -->
<div class="cs-modal" id="resolveModal" role="dialog" aria-modal="true" aria-labelledby="resolveTitle">
    <div class="cs-modal-dialog">
        <form id="resolveForm">
            <div class="cs-modal-body">
                <span class="cs-confirm-icon"><i class="bi bi-check2-all" aria-hidden="true"></i></span>
                <h2 id="resolveTitle">Mark as resolved?</h2>
                <p class="cs-muted" id="resolveText">Only do this when the problem is fixed. This closes the report.</p>
                <div class="cs-field">
                    <label class="cs-label" for="resolveMessage">Message to the resident <span class="cs-subtle">(optional)</span></label>
                    <textarea id="resolveMessage" class="cs-textarea" rows="3" maxlength="1000" placeholder="Example: The garbage has been collected. Thank you for reporting!"></textarea>
                </div>
            </div>
            <div class="cs-modal-footer">
                <button type="button" class="cs-btn cs-btn-ghost" data-modal-close>Cancel</button>
                <button type="submit" class="cs-btn cs-btn-primary" id="resolveSubmit">
                    <i class="bi bi-check2-all" aria-hidden="true"></i> Mark as Resolved
                </button>
            </div>
        </form>
    </div>
</div>

<!-- CHANGE STATUS (accepted reports) -->
<div class="cs-modal" id="statusModal" role="dialog" aria-modal="true" aria-labelledby="statusTitle">
    <div class="cs-modal-dialog">
        <form id="statusForm" novalidate>
            <div class="cs-modal-body">
                <span class="cs-confirm-icon"><i class="bi bi-arrow-left-right" aria-hidden="true"></i></span>
                <h2 id="statusTitle">Change the status</h2>
                <p class="cs-muted" id="statusText">Choose the new status. The resident will be notified.</p>

                <fieldset class="rp-status-options">
                    <legend class="cs-label">New status</legend>
                    <label class="rp-status-option is-resolved">
                        <input type="radio" name="newStatus" value="STAT003">
                        <span>
                            <strong><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Resolved</strong>
                            <small>The problem has been fixed. This closes the report.</small>
                        </span>
                    </label>
                    <label class="rp-status-option is-rejected">
                        <input type="radio" name="newStatus" value="rejected">
                        <span>
                            <strong><i class="bi bi-x-circle-fill" aria-hidden="true"></i> Rejected</strong>
                            <small>The barangay will not act on this report. A reason is required.</small>
                        </span>
                    </label>
                    <label class="rp-status-option is-pending">
                        <input type="radio" name="newStatus" value="STAT001">
                        <span>
                            <strong><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Back to New</strong>
                            <small>Accepted by mistake? Move it back to Pending to review it again.</small>
                        </span>
                    </label>
                </fieldset>

                <div class="rp-status-reasons" id="statusReasons" hidden>
                    <span class="cs-label">Quick reasons</span>
                    <div class="rp-chip-row">
                        <?php foreach ([
                            "This problem was already reported.",
                            "The location is outside our barangay.",
                            "This is not a problem the barangay handles.",
                            "After checking the site, we found no problem.",
                        ] as $reason): ?>
                            <button type="button" class="rp-chip" data-reason="<?= csEscape($reason) ?>"><?= csEscape($reason) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="cs-field">
                    <label class="cs-label" for="statusMessage" id="statusMessageLabel">Message to the resident <span class="cs-subtle">(optional)</span></label>
                    <textarea id="statusMessage" class="cs-textarea" rows="3" maxlength="1000"></textarea>
                </div>
            </div>
            <div class="cs-modal-footer">
                <button type="button" class="cs-btn cs-btn-ghost" data-modal-close>Cancel</button>
                <button type="submit" class="cs-btn cs-btn-primary" id="statusSubmit">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> Save Status
                </button>
            </div>
        </form>
    </div>
</div>
