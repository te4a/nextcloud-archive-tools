<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Service;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Events\InvalidateMountCacheEvent;
use OCP\IUser;
use OCA\ArchiveTools\Mount\MountProvider;
use OCP\Files\Storage\IStorageFactory;

/** Refresh every cached projection of a folder-owned connection. */
final class MountInvalidator {
    public function __construct(
        private IUserMountCache $cache,
        private IEventDispatcher $events,
        private MountProvider $provider,
        private IStorageFactory $factory,
    ) {}

    public function invalidate(int $parentId, ?IUser $actor = null): void {
        $users = [];
        if ($actor !== null) {
            $users[$actor->getUID()] = $actor;
        }
        foreach ($this->cache->getMountsForFileId($parentId) as $mount) {
            $user = $mount->getUser();
            $users[$user->getUID()] = $user;
        }
        foreach ($users as $user) {
            // Persist the new projection immediately. A MOVE may complete a
            // filesystem setup after firing its invalidation event, restoring
            // the core cache marker before the next request.
            $this->cache->registerMounts($user, $this->provider->getMountsForUser($user, $this->factory), [MountProvider::class]);
            $this->events->dispatchTyped(new InvalidateMountCacheEvent($user));
        }
    }
}
