<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Service;

use OCA\ArchiveTools\Archive\OperationBudget;
use OCA\ArchiveTools\Storage\ArchiveStorage;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\Lock\ILockingProvider;
use OCP\Security\ICrypto;

/** Give one archive member an ordinary, private, read-only Files identity. */
final class MemberOpenService {
    private const MAX_DEPTH = 5;

    public function __construct(
        private ArchiveService $archives,
        private MountRepository $mounts,
        private MountInitializer $initializer,
        private MountInvalidator $invalidator,
        private AdmissionService $admission,
        private LifecycleService $lifecycle,
        private IRootFolder $root,
        private ICrypto $crypto,
    ) {}

    public function open(IUser $actor, int $sourceId, string $member, string $expectedEtag, #[\SensitiveParameter] ?string $password): int {
        AdmissionService::validatePassword($password);
        $owner = $actor->getUID();
        $source = $this->archives->source($owner, $sourceId, allowMounted: true);
        $depth = 1 + (int)$source->getMountPoint()->getOption('archive_tools_preview_depth', 0);
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException('The maximum number of nested archive levels has been reached.');
        }
        $source->lock(ILockingProvider::LOCK_SHARED);
        try {
            $etag = $this->archives->currentEtag($source, $expectedEtag);
            $index = $this->archives->memberIndex($this->archives->localPath($source), $member, $password, new OperationBudget());
            $name = '.archive-tools-' . substr(hash('sha256', json_encode([$owner, $sourceId, $etag, $member], JSON_THROW_ON_ERROR)), 0, 32);
            $indexJson = json_encode($index, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $secret = $password === null || $password === '' ? null : $this->crypto->encrypt($password);
            return $this->lifecycle->write(function () use ($actor, $owner, $source, $etag, $member, $name, $indexJson, $secret, $depth): int {
                $id = $this->mounts->findPreview($owner, $source->getId(), $name);
                if ($id === null) {
                    if ($this->root->getUserFolder($owner)->nodeExists($name)) {
                        throw new \RuntimeException('The archive folder is not available in your file space.');
                    }
                    $this->admission->assertConnection($owner, $indexJson, $secret);
                    $id = $this->mounts->create([
                        'source_id' => $source->getId(),
                        'parent_id' => $source->getParent()->getId(),
                        'mount_name' => $name,
                        'created_by' => $owner,
                        'source_etag' => $etag,
                        'index_json' => $indexJson,
                        'password' => $secret,
                        'pinned' => 0,
                        'last_access' => time(),
                        'preview_depth' => $depth,
                    ]);
                }
                $filename = basename($member);
                if (!ArchiveStorage::fitsCachePath(ArchiveStorage::branch($id), $filename)) {
                    throw new \InvalidArgumentException('An archive member path is too long to connect this archive. Open the archive to browse its contents.');
                }
                $lease = $this->mounts->beginReadLease($id);
                try {
                    $fileId = $this->initializer->initialize($id, $actor, $filename);
                    $this->invalidator->invalidateUser($actor);
                    return $fileId;
                } finally {
                    $this->mounts->endReadLease($lease);
                }
            });
        } finally {
            $source->unlock(ILockingProvider::LOCK_SHARED);
        }
    }
}
