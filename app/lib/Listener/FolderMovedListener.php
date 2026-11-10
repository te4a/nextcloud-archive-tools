<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Listener;

use OCA\ArchiveTools\Service\MountInvalidator;
use OCA\ArchiveTools\Service\LifecycleService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\IUserSession;
use OCP\Share\Events\ShareMovedEvent;

/** Refresh projections when a recipient changes a shared folder's alias. */
final class FolderMovedListener implements IEventListener {
    public function __construct(private MountInvalidator $invalidator, private IUserSession $session, private LifecycleService $lifecycle) {}

    public function handle(Event $event): void {
        try { $this->lifecycle->write(function () use ($event): void {
        if ($event instanceof NodeRenamedEvent) {
            $this->invalidator->invalidate($event->getTarget()->getId(), $this->session->getUser());
        } elseif ($event instanceof ShareMovedEvent) {
            $this->invalidator->invalidate($event->getShare()->getNodeId(), $event->getUser());
        }
        }); } catch (\Throwable) { /* Core moves remain usable during upgrade/purge. */ }
    }
}
