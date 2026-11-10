<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Service;

use OCA\ArchiveTools\Mount\MountProvider;
use OCP\Files\Storage\IStorageFactory;
use OCP\IUser;

/** Seed the bounded metadata index before publishing a new connection. */
final class MountInitializer {
    public function __construct(private MountProvider $provider, private IStorageFactory $factory) {}

    public function initialize(int $id, IUser $actor): void {
        foreach ($this->provider->getMountsForUser($actor, $this->factory) as $mount) {
            if ($mount->getMountId() !== $id) {
                continue;
            }
            $storage = $mount->getStorage();
            if ($storage === null || !$storage->test()) {
                throw new \RuntimeException('The archive connection could not be initialized.');
            }
            $storage->getScanner()->scan('');
            if (!$storage->getCache()->get('')) {
                throw new \RuntimeException('The archive index could not be initialized.');
            }
            return;
        }
        throw new \RuntimeException('The archive folder is not available in your file space.');
    }
}
