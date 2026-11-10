<?php
/** @var \OCP\IL10N $l */
?>
<section class="section archive-tools-admin" aria-labelledby="archive-tools-admin-title">
    <h2 id="archive-tools-admin-title"><?php p($l->t('Archive Tools')); ?></h2>
    <p class="settings-hint"><?php p($l->t('These settings apply to all users.')); ?></p>
    <form id="archive-tools-settings-form">
        <div class="archive-tools-setting">
            <input type="checkbox" class="checkbox" id="archive-tools-open-by-click" name="openByClick">
            <label for="archive-tools-open-by-click"><?php p($l->t('Open supported archives on click for all users')); ?></label>
        </div>
        <div class="archive-tools-setting">
            <label for="archive-tools-idle-timeout"><?php p($l->t('Disconnect inactive archives after (minutes)')); ?></label>
            <input type="number" id="archive-tools-idle-timeout" name="idleTimeoutMinutes" min="0" max="10080" step="1" required aria-describedby="archive-tools-idle-help">
            <p class="settings-hint" id="archive-tools-idle-help"><?php p($l->t('Use 0 to disable automatic disconnection. Shared archives use activity from all participants.')); ?></p>
        </div>
        <div class="archive-tools-setting">
            <input type="checkbox" class="checkbox" id="archive-tools-allow-pin" name="allowPin">
            <label for="archive-tools-allow-pin"><?php p($l->t('Allow archives to be pinned against automatic disconnection')); ?></label>
        </div>
        <div class="archive-tools-setting">
            <label for="archive-tools-jobs-retention"><?php p($l->t('Keep completed task history for (days)')); ?></label>
            <input type="number" id="archive-tools-jobs-retention" name="jobsRetentionDays" min="0" max="3650" step="1" required aria-describedby="archive-tools-jobs-retention-help">
            <p class="settings-hint" id="archive-tools-jobs-retention-help"><?php p($l->t('Use 0 to keep task history indefinitely. Extracted files are never deleted. Failed or cancelled tasks with partial output are kept for review.')); ?></p>
        </div>
        <button type="submit" class="primary" id="archive-tools-settings-save"><?php p($l->t('Save')); ?></button>
        <span id="archive-tools-settings-status" role="status" aria-live="polite"></span>
    </form>
</section>
