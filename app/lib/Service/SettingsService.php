<?php

declare(strict_types=1);

namespace OCA\ArchiveTools\Service;

use InvalidArgumentException;
use OCA\ArchiveTools\AppInfo\Application;
use OCP\IAppConfig;

class SettingsService {
    public const MAX_IDLE_TIMEOUT_MINUTES = 10080;
    public const MAX_JOBS_RETENTION_DAYS = 3650;
    public const DEFAULT_MOUNT_ARCHIVE_SIZE_MIB = 4096;
    public const BYTES_PER_MIB = 1048576;
    // Keep the conversion from MiB to bytes within the PHP integer range.
    private const MAX_MOUNT_ARCHIVE_SIZE_MIB = PHP_INT_MAX >> 20;

    public function __construct(private IAppConfig $config, private LifecycleService $lifecycle) {
    }

    /** @return array{openByClick: bool, idleTimeoutMinutes: int, allowPin: bool, jobsRetentionDays: int, maxMountArchiveSizeMiB: int, zipMountMode: string} */
    public function getSettings(): array {
        return [
            'openByClick' => $this->config->getValueBool(Application::APP_ID, 'openByClick', true),
            'idleTimeoutMinutes' => $this->config->getValueInt(Application::APP_ID, 'idleTimeoutMinutes', 60),
            'allowPin' => $this->config->getValueBool(Application::APP_ID, 'allowPin', true),
            'jobsRetentionDays' => $this->config->getValueInt(Application::APP_ID, 'jobsRetentionDays', 30),
            'maxMountArchiveSizeMiB' => $this->maxMountArchiveSizeMiB(),
            'zipMountMode' => $this->zipMountMode(),
        ];
    }

    public function zipMountMode(): string {
        return $this->config->getValueString(Application::APP_ID, 'zipMountMode', 'full') === 'on_demand' ? 'on_demand' : 'full';
    }

    public function maxMountArchiveSizeMiB(): int {
        $limit = $this->config->getValueInt(Application::APP_ID, 'maxMountArchiveSizeMiB', self::DEFAULT_MOUNT_ARCHIVE_SIZE_MIB);
        return $limit >= 1 && $limit <= self::MAX_MOUNT_ARCHIVE_SIZE_MIB ? $limit : self::DEFAULT_MOUNT_ARCHIVE_SIZE_MIB;
    }

    /**
     * Validate the complete request before writing any setting.
     *
     * @return array{openByClick: bool, idleTimeoutMinutes: int, allowPin: bool, jobsRetentionDays: int, maxMountArchiveSizeMiB: int, zipMountMode: string}
     */
    public function update(mixed $openByClick, mixed $idleTimeoutMinutes, mixed $allowPin, mixed $jobsRetentionDays = null, mixed $maxMountArchiveSizeMiB = null, mixed $zipMountMode = null): array {
        return $this->lifecycle->write(fn (): array => $this->updateReady($openByClick, $idleTimeoutMinutes, $allowPin, $jobsRetentionDays, $maxMountArchiveSizeMiB, $zipMountMode));
    }

    private function updateReady(mixed $openByClick, mixed $idleTimeoutMinutes, mixed $allowPin, mixed $jobsRetentionDays, mixed $maxMountArchiveSizeMiB, mixed $zipMountMode): array {
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
        if ($maxMountArchiveSizeMiB !== null && (!is_int($maxMountArchiveSizeMiB)
            || $maxMountArchiveSizeMiB < 1
            || $maxMountArchiveSizeMiB > self::MAX_MOUNT_ARCHIVE_SIZE_MIB)) {
            throw new InvalidArgumentException('The archive connection size limit must be a positive whole number in MiB that fits on this server.');
        }
        if ($zipMountMode !== null && !in_array($zipMountMode, ['full', 'on_demand'], true)) {
            throw new InvalidArgumentException('Invalid ZIP connection mode.');
        }

        $this->config->setValueBool(Application::APP_ID, 'openByClick', $openByClick);
        $this->config->setValueInt(Application::APP_ID, 'idleTimeoutMinutes', $idleTimeoutMinutes);
        $this->config->setValueBool(Application::APP_ID, 'allowPin', $allowPin);
        $this->config->setValueInt(Application::APP_ID, 'jobsRetentionDays', $jobsRetentionDays);
        if ($maxMountArchiveSizeMiB !== null) {
            $this->config->setValueInt(Application::APP_ID, 'maxMountArchiveSizeMiB', $maxMountArchiveSizeMiB);
        }
        if ($zipMountMode !== null) {
            $this->config->setValueString(Application::APP_ID, 'zipMountMode', $zipMountMode);
        }

        return $this->getSettings();
    }
}
