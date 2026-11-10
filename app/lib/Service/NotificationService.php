<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Service;

use DateTime;
use OCP\Notification\IManager;

final class NotificationService {
    public function __construct(private IManager $notifications) {}

    public function jobFinished(string $owner, int $jobId, string $sourceName, string $status, ?int $targetId = null): void {
        if (!in_array($status, ['succeeded', 'failed'], true)) {
            return;
        }
        $notification = $this->notifications->createNotification()
            ->setApp('archive_tools')
            ->setUser($owner)
            ->setDateTime(new DateTime())
            ->setObject('extraction', (string)$jobId)
            ->setSubject('extraction.' . $status, ['sourceName' => $sourceName, 'targetId' => $targetId]);
        $this->notifications->notify($notification);
    }
}
