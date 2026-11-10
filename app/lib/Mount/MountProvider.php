<?php

declare(strict_types=1);

namespace OCA\ArchiveTools\Mount;

use OCA\ArchiveTools\Archive\ArchiveReader;
use OCA\ArchiveTools\Service\MountRepository;
use OCA\ArchiveTools\Service\LifecycleService;
use OCA\ArchiveTools\Storage\ArchiveStorage;
use OCP\Files\Config\IMountProvider;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IRootFolder;
use OCP\Files\Storage\IStorageFactory;
use OCP\IUser;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/** Project a folder-owned archive connection into each recipient's namespace. */
final class MountProvider implements IMountProvider {
    public function __construct(
        private MountRepository $mounts,
        private IUserMountCache $mountCache,
        private IRootFolder $rootFolder,
        private ArchiveReader $reader,
        private ICrypto $crypto,
        private IMimeTypeDetector $mimeTypes,
        private LoggerInterface $logger,
        private LifecycleService $lifecycle,
    ) {}

    public function getMountsForUser(IUser $user, IStorageFactory $loader): array {
        try { $activity = $this->lifecycle->enter(); }
        catch (\Throwable) {
            // During first install/upgrade the state table may not exist yet.
            // Suppress only our mounts, never break the core filesystem/occ.
            return [];
        }
        try { return $this->getReadyMountsForUser($user, $loader); }
        finally { $activity->close(); }
    }

    private function getReadyMountsForUser(IUser $user, IStorageFactory $loader): array {
        $result = [];
        $userRoot = '/' . $user->getUID() . '/files';

        foreach ($this->mounts->findAll() as $row) {
            $name = (string)$row['mount_name'];
            if ($name === '' || $name === '.' || $name === '..'
                || strpbrk($name, "/\\\0") !== false) {
                continue;
            }

            try {
                // This cache lookup needs no File object and does not recursively
                // initialise the share provider, which core assembles last.
                $parents = $this->mountCache->getMountsForFileId((int)$row['parent_id'], $user->getUID());
                foreach ($parents as $parent) {
                    if ($parent->getMountProvider() === self::class) {
                        continue;
                    }
                    $parentPath = rtrim($parent->getPath(), '/');
                    if ($parentPath !== $userRoot && !str_starts_with($parentPath, $userRoot . '/')) {
                        continue;
                    }
                    $target = $parentPath . '/' . $name . '/';
                    if (isset($result[$target])) {
                        continue;
                    }

                    // Source/parent access is resolved lazily by the storage.
                    // Cached mount locations are candidates, never authorization.
                    $result[$target] = new ArchiveMountPoint(
                        storage: ArchiveStorage::class,
                        mountpoint: $target,
                        arguments: [
                            'mount' => $row,
                            'viewer' => $user->getUID(),
                            'repository' => $this->mounts,
                            'rootFolder' => $this->rootFolder,
                            'reader' => $this->reader,
                            'crypto' => $this->crypto,
                            'mimeTypes' => $this->mimeTypes,
                        ],
                        loader: $loader,
                        mountOptions: [
                            'readonly' => true,
                            'enable_sharing' => false,
                            'filesystem_check_changes' => 1,
                            'previews' => true,
                        ],
                        mountId: (int)$row['id'],
                        mountProvider: self::class,
                    );
                }
            } catch (\Throwable $error) {
                // Do not serialize the row: it contains the encrypted password.
                $this->logger->warning('An archive connection could not be projected.', [
                    'app' => 'archive_tools',
                    'connectionId' => (int)$row['id'],
                    'exceptionClass' => $error::class,
                ]);
            }
        }

        return array_values($result);
    }
}
