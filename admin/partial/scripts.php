<?php
/*
 * CitiSense admin — shared UI scripts and the reusable confirm dialog.
 * Include just before </body>, before any page-specific <script>.
 */
?>
<div class="cs-toast-region" id="csToastRegion" role="status" aria-live="polite"></div>

<div class="cs-modal cs-modal-sm" id="csConfirmModal" role="dialog" aria-modal="true" aria-labelledby="csConfirmTitle">
    <div class="cs-modal-dialog">
        <div class="cs-modal-body">
            <span class="cs-confirm-icon" id="csConfirmIcon"><i class="bi bi-question-lg" aria-hidden="true"></i></span>
            <h2 id="csConfirmTitle">Are you sure?</h2>
            <p class="cs-muted" id="csConfirmMessage"></p>
        </div>
        <div class="cs-modal-footer">
            <button type="button" class="cs-btn cs-btn-ghost" data-confirm-cancel>Cancel</button>
            <button type="button" class="cs-btn cs-btn-primary" data-confirm-ok>Confirm</button>
        </div>
    </div>
</div>

<script src="../js/citisense.js?v=<?= $csAssetVersion ?? "2.0" ?>"></script>
