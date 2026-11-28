/* global OC, t */
(() => {
    'use strict';

    function initialize() {
        const form = document.getElementById('archive-tools-settings-form');
        const initialState = document.getElementById('initial-state-archive_tools-settings');
        if (!form || !initialState || form.dataset.initialized === 'true') {
            return;
        }
        form.dataset.initialized = 'true';

        const status = document.getElementById('archive-tools-settings-status');
        const save = document.getElementById('archive-tools-settings-save');
        const openByClick = document.getElementById('archive-tools-open-by-click');
        const timeout = document.getElementById('archive-tools-idle-timeout');
        const allowPin = document.getElementById('archive-tools-allow-pin');
        const mountSize = document.getElementById('archive-tools-mount-size');
        const zipMountMode = document.getElementById('archive-tools-zip-mount-mode');
        const retention = document.getElementById('archive-tools-jobs-retention');

        function showStatus(message, failed = false) {
            status.textContent = message;
            status.classList.toggle('archive-tools-error', failed);
        }

        function applySettings(settings) {
            openByClick.checked = settings.openByClick;
            timeout.value = String(settings.idleTimeoutMinutes);
            allowPin.checked = settings.allowPin;
            if (Number.isSafeInteger(settings.maxMountArchiveSizeMiB)) {
                mountSize.value = String(settings.maxMountArchiveSizeMiB);
            }
            retention.value = String(settings.jobsRetentionDays ?? 30);
            zipMountMode.value = settings.zipMountMode === 'on_demand' ? 'on_demand' : 'full';
        }

        try {
            applySettings(JSON.parse(atob(initialState.value)));
        } catch {
            save.disabled = true;
            showStatus(t('archive_tools', 'Could not load archive settings. Reload this page.'), true);
            return;
        }

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!form.reportValidity()) {
                return;
            }
            const maxMountArchiveSizeMiB = mountSize.valueAsNumber;
            if (!Number.isSafeInteger(maxMountArchiveSizeMiB)) {
                showStatus(t('archive_tools', 'The archive connection size limit must be a positive whole number in MiB that fits on this server.'), true);
                mountSize.focus();
                return;
            }
            save.disabled = true;
            showStatus(t('archive_tools', 'Saving…'));
            try {
                const response = await fetch(OC.generateUrl('/apps/archive_tools/settings'), {
                    method: 'PUT',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'requesttoken': OC.requestToken,
                    },
                    body: JSON.stringify({
                        openByClick: openByClick.checked,
                        idleTimeoutMinutes: Number(timeout.value),
                        allowPin: allowPin.checked,
                        maxMountArchiveSizeMiB,
                        zipMountMode: zipMountMode.value,
                        jobsRetentionDays: Number(retention.value),
                    }),
                });
                const result = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(result.message || t('archive_tools', 'Could not save archive settings. Try again.'));
                }
                applySettings(result);
                showStatus(t('archive_tools', 'Settings saved.'));
            } catch (error) {
                showStatus(error instanceof Error
                    ? error.message
                    : t('archive_tools', 'Could not save archive settings. Try again.'), true);
            } finally {
                save.disabled = false;
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})();
