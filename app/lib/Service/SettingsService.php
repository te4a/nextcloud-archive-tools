<?php

declare(strict_types=1);

namespace OCA\ArchiveTools\Service;

use InvalidArgumentException;
use OCA\ArchiveTools\AppInfo\Application;
use OCP\IAppConfig;

class SettingsService {
    public const MAX_IDLE_TIMEOUT_MINUTES = 10080;
    public const MAX_JOBS_RETENTION_DAYS = 3650;

    public function __construct(private IAppConfig $config, private LifecycleService $lifecycle) {
    }

    /** @return array{openByClick: bool, idleTimeoutMinutes: int, allowPin: bool, jobsRetentionDays: int} */
    public function getSettings(): array {
        return [
            'openByClick' => $this->config->getValueBool(Application::APP_ID, 'openByClick', true),
            'idleTimeoutMinutes' => $this->config->getValueInt(Application::APP_ID, 'idleTimeoutMinutes', 60),
            'allowPin' => $this->config->getValueBool(Application::APP_ID, 'allowPin', true),
            'jobsRetentionDays' => $this->config->getValueInt(Application::APP_ID, 'jobsRetentionDays', 30),
        ];
    }

    /**
     * Validate the complete request before writing any setting.
     *
     * @return array{openByClick: bool, idleTimeoutMinutes: int, allowPin: bool, jobsRetentionDays: int}
     */
    public function update(mixed $openByClick, mixed $idleTimeoutMinutes, mixed $allowPin, mixed $jobsRetentionDays = null): array {
        return $this->lifecycle->write(fn (): array => $this->updateReady($openByClick, $idleTimeoutMinutes, $allowPin, $jobsRetentionDays));
    }

    private function updateReady(mixed $openByClick, mixed $idleTimeoutMinutes, mixed $allowPin, mixed $jobsRetentionDays): array {
        if (!is_bool($openByClick) || !is_bool($allowPin)) {
            throw new InvalidArgumentException('The opening and pinning settings must be boolean values.');
        }
        if (!is_int($idleTimeoutMinutes)
            || $idleTimeoutMinutes < 0
            || $idleTimeoutMinutes > self::MAX_IDLE_TIMEOUT_MINUTES) {
            throw new InvalidArgumentException('The inactivity timeout must be a whole number between 0 and 10080 minutes.');
        }
        // Older clients omit this setting; preserve the administrator's value.
        $jobsRetentionDays ??= $this->getSettings()['jobsRetentionDays'];
        if (!is_int($jobsRetentionDays)
            || $jobsRetentionDays < 0
            || $jobsRetentionDays > self::MAX_JOBS_RETENTION_DAYS) {
            throw new InvalidArgumentException('The task history retention must be a whole number between 0 and 3650 days.');
        }

        $this->config->setValueBool(Application::APP_ID, 'openByClick', $openByClick);
        $this->config->setValueInt(Application::APP_ID, 'idleTimeoutMinutes', $idleTimeoutMinutes);
        $this->config->setValueBool(Application::APP_ID, 'allowPin', $allowPin);
        $this->config->setValueInt(Application::APP_ID, 'jobsRetentionDays', $jobsRetentionDays);

        return $this->getSettings();
    }
}
