<?php

declare(strict_types=1);

namespace OCA\ArchiveTools\Service;

use OCP\Share\Events\ShareDeletedEvent;

/** Limit folder-share cleanup to entries that already have Nextcloud file IDs. */
final class ShareDeletionScope {
    private int $folderEvents = 0;

    public function begin(ShareDeletedEvent $event): void {
        if ($event->getShare()->getNodeType() === 'folder') {
            $this->folderEvents++;
        }
    }

    public function end(ShareDeletedEvent $event): void {
        if ($event->getShare()->getNodeType() === 'folder' && $this->folderEvents > 0) {
            $this->folderEvents--;
        }
    }

    public function isActive(): bool {
        return $this->folderEvents > 0;
    }
}
