<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Controller;

use OCA\ArchiveTools\Archive\ArchiveReader;
use OCA\ArchiveTools\Archive\MountArchiveMemberTooLargeException;
use OCA\ArchiveTools\Archive\MountArchiveSizeExceededException;
use OCA\ArchiveTools\Archive\OperationBudget;
use OCA\ArchiveTools\Archive\ZipFolderIndex;
use OCA\ArchiveTools\Archive\ZipIndexLimitException;
use OCA\ArchiveTools\Service\ArchiveChangedException;
use OCA\ArchiveTools\Service\ArchiveService;
use OCA\ArchiveTools\Service\MountRepository;
use OCA\ArchiveTools\Service\MountInvalidator;
use OCA\ArchiveTools\Service\MountInitializer;
use OCA\ArchiveTools\Service\SettingsService;
use OCA\ArchiveTools\Service\LifecycleService;
use OCA\ArchiveTools\Service\AdmissionService;
use OCA\ArchiveTools\Service\ResourceLimitException;
use OCA\ArchiveTools\Storage\ArchiveStorage;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use OCP\Lock\LockedException;
use Throwable;

final class MountController extends Controller {
    public function __construct(
        IRequest $request,
        private IUserSession $userSession,
        private IRootFolder $rootFolder,
        private MountRepository $mounts,
        private ArchiveService $archives,
        private ArchiveReader $reader,
        private ICrypto $crypto,
        private SettingsService $settings,
        private MountInvalidator $invalidator,
        private MountInitializer $initializer,
        private IL10N $l,
        private LifecycleService $lifecycle,
        private AdmissionService $admission,
    ) {
        parent::__construct('archive_tools', $request);
    }

    private function userFolder(): Folder {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new \RuntimeException('Authentication is required.');
        }
        return $this->rootFolder->getUserFolder($user->getUID());
    }

    private function nodeById(int $id): \OCP\Files\Node {
        foreach ($this->userFolder()->getById($id) as $node) {
            if ($node->isReadable()) {
                return $node;
            }
        }
        throw new NotFoundException('File not found.');
    }

    private function safeRow(array $row, ?\OCP\Files\Node $parent = null, ?\OCP\Files\Node $source = null): array {
        $info = json_decode($row['index_json'], true, 512, JSON_THROW_ON_ERROR);
        $parent ??= $this->nodeById((int)$row['parent_id']);
        $source ??= $this->nodeById((int)$row['source_id']);
        $available = $source instanceof File
            && $source->getParent()->getId() === (int)$row['parent_id']
            && hash_equals((string)$row['source_etag'], $source->getEtag());
        return [
            'id' => (int)$row['id'], 'sourceId' => (int)$row['source_id'],
            'parentId' => (int)$row['parent_id'], 'name' => $row['mount_name'],
            'createdBy' => $row['created_by'], 'pinned' => (bool)$row['pinned'],
            'lastAccess' => (int)$row['last_access'],
            'fileCount' => $info['fileCount'], 'totalSize' => $info['totalSize'],
            'encrypted' => (bool)($info['encrypted'] ?? false),
            'path' => $this->mountPath($row, $parent),
            'sourceName' => $source->getName(),
            'sourcePath' => $this->userFolder()->getRelativePath($source->getPath()),
            'canManage' => $this->canManage($parent, $source),
            'available' => $available,
            'state' => $available ? 'ready' : 'source_changed',
        ];
    }

    private function mountPath(array $row, \OCP\Files\Node $parent): string {
        $relativeParent = $this->userFolder()->getRelativePath($parent->getPath());
        return ltrim(rtrim((string)$relativeParent, '/') . '/' . $row['mount_name'], '/');
    }

    private function canDisconnectUnavailable(\OCP\Files\Node $parent): bool {
        $user = $this->userSession->getUser();
        return $user !== null && $parent instanceof Folder && $parent->isCreatable()
            && (bool)($parent->getPermissions() & \OCP\Constants::PERMISSION_UPDATE)
            && $parent->getOwner()?->getUID() === $user->getUID();
    }

    private function canManage(\OCP\Files\Node $parent, \OCP\Files\Node $source): bool {
        // DELETE on a received share root means "unshare for myself". It
        // does not authorize changing a shared connection for everybody.
        return $parent instanceof Folder && $parent->isCreatable()
            && (bool)($parent->getPermissions() & \OCP\Constants::PERMISSION_UPDATE)
            && $source->isDeletable();
    }

    #[NoAdminRequired]
    public function index(): JSONResponse {
        $result = [];
        foreach ($this->mounts->findAll() as $row) {
            if ((int)($row['preview_depth'] ?? 0) > 0) {
                continue;
            }
            try {
                $parent = $this->nodeById((int)$row['parent_id']);
            } catch (NotFoundException) {
                continue;
            }
            try {
                $source = $this->nodeById((int)$row['source_id']);
            } catch (NotFoundException) {
                if ($this->canDisconnectUnavailable($parent)) {
                    $result[] = [
                        'id' => (int)$row['id'], 'parentId' => (int)$row['parent_id'],
                        'name' => $row['mount_name'], 'pinned' => (bool)$row['pinned'],
                        'path' => $this->mountPath($row, $parent),
                        'canManage' => true, 'available' => false, 'state' => 'source_unavailable',
                    ];
                }
                continue;
            }
            // Load the index only for a connection this viewer can access.
            $current = $this->mounts->find((int)$row['id']);
            if ($current !== null) {
                try {
                    $result[] = $this->safeRow($current, $parent, $source);
                } catch (NotFoundException) {
                    // The source or its parent changed while the list was being built.
                }
            }
        }
        return new JSONResponse($result);
    }

    #[NoAdminRequired]
    public function create(int $sourceId, string $name = '', #[\SensitiveParameter] ?string $password = null, bool $pinned = false, ?string $expectedEtag = null, string $mode = 'default'): JSONResponse {
        $lockedSource = null;
        $activity = null;
        try {
            AdmissionService::validatePassword($password);
            if (!in_array($mode, ['default', 'on_demand'], true)) {
                throw new \InvalidArgumentException('Invalid ZIP connection mode.');
            }
            $activity = $this->lifecycle->enter();
            $source = $this->nodeById($sourceId);
            if (!$source instanceof File) {
                return new JSONResponse(['message' => $this->l->t('Select an archive file.')], 400);
            }
            $parent = $source->getParent();
            if (!$parent->isCreatable()) {
                return new JSONResponse(['message' => $this->l->t('You cannot create a connection in this folder.')], 403);
            }
            if ($pinned && !$this->settings->getSettings()['allowPin']) {
                return new JSONResponse(['message' => $this->l->t('Pinning archive connections is disabled by the administrator.')], 403);
            }
            $source->lock(\OCP\Lock\ILockingProvider::LOCK_SHARED);
            $lockedSource = $source;
            $sourceEtag = $this->archives->currentEtag($source, $expectedEtag);
            if ($name === '') {
                $name = ArchiveReader::baseName($source->getName()) . ' (archive)';
            }
            if ($name === '' || strlen($name) > 240 || preg_match('/[\x00-\x1f\/\\\\]/', $name) || $name === '.' || $name === '..') {
                return new JSONResponse(['message' => $this->l->t('Invalid folder name.')], 400);
            }
            if ($parent->nodeExists($name)) {
                return new JSONResponse(['message' => $this->l->t('A file or folder with this name already exists.')], 409);
            }
            foreach ($this->mounts->findAll() as $existing) {
                if ((int)$existing['parent_id'] === $parent->getId() && $existing['mount_name'] === $name) {
                    return new JSONResponse(['message' => $this->l->t('This connection already exists.')], 409);
                }
            }
            // Cheap preflight only; the final check and insert below share the
            // durable writer barrier so simultaneous requests cannot overbook.
            $owner = $this->userSession->getUser()->getUID();
            $this->admission->assertConnection($owner);
            $localPath = $source->getStorage()->getLocalFile($source->getInternalPath());
            if (!is_string($localPath)) {
                throw new \RuntimeException('Unable to read the archive.');
            }
            $sizeLimitMiB = $this->settings->maxMountArchiveSizeMiB();
            $onDemand = $mode === 'on_demand'
                || ($this->settings->zipMountMode() === 'on_demand' && $this->reader->detect($localPath) === 'zip');
            $budget = $onDemand ? new OperationBudget(25) : null;
            try {
                $index = $this->reader->inspectForMount($localPath, $password, $sizeLimitMiB * SettingsService::BYTES_PER_MIB, $onDemand, $budget);
            } catch (MountArchiveSizeExceededException) {
                return $this->sizeLimitResponse($sizeLimitMiB);
            } catch (ZipIndexLimitException $error) {
                if ($onDemand || !$error->canRetryOnDemand || !function_exists('gzdeflate')) { throw $error; }
                return new JSONResponse(['message' => $this->l->t($error->getMessage()),
                    'code' => 'mount_retry_on_demand', 'sourceEtag' => $sourceEtag], 422);
            }
            if ($index['encrypted'] && ($password === null || $password === '')) {
                return new JSONResponse(['message' => $this->l->t('This archive requires a password.')], 422);
            }
            // Validate protected members before publishing a shared connection.
            if ($index['encrypted']) {
                foreach ($index['entries'] as $entry) {
                    if ($entry['type'] === 'file' && $entry['encrypted']) {
                        $stream = $this->reader->streamForMount($localPath, $entry['originalName'], $password, max(1, $index['totalSize']));
                        try { while (!feof($stream)) { if (fread($stream, 65536) === false) { throw new \RuntimeException('Unable to verify the archive password.'); } } }
                        finally { fclose($stream); }
                        break;
                    }
                }
            }
            $longestPath = 0;
            foreach ($index['entries'] as $member => $_entry) {
                $longestPath = max($longestPath, mb_strlen((string)$member, 'UTF-8'));
            }
            if ($onDemand) {
                ZipFolderIndex::pack($index, $budget);
            }
            $indexJson = json_encode($index, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $encryptedPassword = $password === null ? null : $this->crypto->encrypt($password);
            return $this->lifecycle->write(function () use ($source, $parent, $owner, $name, $pinned, $sourceEtag, $onDemand, $longestPath, $indexJson, $encryptedPassword): JSONResponse {
            $this->admission->assertConnection($owner, $indexJson, $encryptedPassword);
            $id = $this->mounts->create([
                'source_id' => $source->getId(), 'parent_id' => $parent->getId(),
                'mount_name' => $name, 'created_by' => $owner,
                'pinned' => (int)$pinned, 'last_access' => time(),
                'source_etag' => $sourceEtag,
                'index_json' => $indexJson,
                'password' => $encryptedPassword,
            ]);
            $branch = ArchiveStorage::branch($id);
            if (!ArchiveStorage::fitsCachePath($branch, str_repeat('a', $longestPath))) {
                throw new \InvalidArgumentException('An archive member path is too long to connect this archive. Open the archive to browse its contents.');
            }
            try {
                $initializationLease = $this->mounts->beginReadLease($id);
                try {
                    $this->initializer->initialize($id, $this->userSession->getUser(), onDemand: $onDemand);
                } finally {
                    $this->mounts->endReadLease($initializationLease);
                }
            } catch (\Throwable $error) {
                $this->mounts->delete($id);
                throw new \RuntimeException('The archive connection could not be initialized.', previous: $error);
            }
            $this->invalidator->invalidate($parent->getId(), $this->userSession->getUser());
            return new JSONResponse($this->safeRow($this->mounts->find($id)), 201);
            });
        } catch (NotFoundException) {
            return new JSONResponse(['message' => $this->l->t('Archive not found.')], 404);
        } catch (\OCP\DB\Exception $e) {
            if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                return new JSONResponse(['message' => $this->l->t('This connection already exists.')], 409);
            }
            throw $e;
        } catch (ArchiveChangedException $e) {
            return new JSONResponse(['message' => $this->l->t($e->getMessage())], 409);
        } catch (LockedException) {
            return new JSONResponse(['message' => $this->l->t('The archive is currently in use. Try again when the operation has finished.')], 409);
        } catch (ResourceLimitException $e) {
            return new JSONResponse(['message' => $this->l->t($e->getMessage()), 'code' => 'resource_limit'], 429);
        } catch (MountArchiveMemberTooLargeException) {
            return new JSONResponse(['message' => $this->l->t('An archive member exceeds the 4 GiB size limit for connections.'), 'code' => 'mount_archive_member_size_limit'], 422);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return new JSONResponse(['message' => $this->l->t($e->getMessage())], 422);
        } finally {
            if ($lockedSource !== null) {
                $lockedSource->unlock(\OCP\Lock\ILockingProvider::LOCK_SHARED);
            }
            $activity?->close();
        }
    }

    private function sizeLimitResponse(int $limitMiB): JSONResponse {
        return new JSONResponse([
            'message' => str_replace('{limit}', (string)$limitMiB, $this->l->t('The expanded archive exceeds the {limit} MiB limit for new connections.')),
            'code' => 'mount_archive_size_limit',
            'limitMiB' => $limitMiB,
        ], 422);
    }

    #[NoAdminRequired]
    public function update(int $id, mixed $pinned): JSONResponse {
        try { return $this->lifecycle->write(fn (): JSONResponse => $this->updateReady($id, $pinned)); }
        catch (LockedException) { return new JSONResponse(['message' => $this->l->t('The archive is currently in use. Try again when the operation has finished.')], 409); }
        catch (\RuntimeException $error) { return new JSONResponse(['message' => $this->l->t($error->getMessage())], 503); }
    }

    private function updateReady(int $id, mixed $pinned): JSONResponse {
        if (!is_bool($pinned)) {
            return new JSONResponse(['message' => $this->l->t('The pinning setting must be a boolean value.')], 400);
        }
        try {
            $visible = $this->mounts->find($id);
            if ($visible === null || (int)($visible['preview_depth'] ?? 0) > 0) {
                return new JSONResponse(['message' => $this->l->t('Connection not found.')], 404);
            }
            $parent = $this->nodeById((int)$visible['parent_id']);
            $source = $this->nodeById((int)$visible['source_id']);
            if (!$this->canManage($parent, $source)) {
                return new JSONResponse(['message' => $this->l->t('You cannot change this shared archive connection.')], 403);
            }
            return $this->mounts->withExclusive($id, function () use ($id, $pinned): JSONResponse {
                $row = $this->mounts->find($id);
                if ($row === null || (int)($row['preview_depth'] ?? 0) > 0) {
                    return new JSONResponse(['message' => $this->l->t('Connection not found.')], 404);
                }
                $parent = $this->nodeById((int)$row['parent_id']);
                $source = $this->nodeById((int)$row['source_id']);
                if (!$this->canManage($parent, $source)) {
                    return new JSONResponse(['message' => $this->l->t('You cannot change this shared archive connection.')], 403);
                }
                if ($pinned && !$this->settings->getSettings()['allowPin']) {
                    return new JSONResponse(['message' => $this->l->t('Pinning archive connections is disabled by the administrator.')], 403);
                }
                $this->mounts->setPinned($id, $pinned);
                return new JSONResponse($this->safeRow($this->mounts->find($id)));
            });
        } catch (NotFoundException) {
            return new JSONResponse(['message' => $this->l->t('Connection not found.')], 404);
        } catch (LockedException) {
            return new JSONResponse(['message' => $this->l->t('The archive is currently in use. Try again when the operation has finished.')], 409);
        }
    }

    #[NoAdminRequired]
    public function destroy(int $id): JSONResponse {
        try { return $this->lifecycle->write(fn (): JSONResponse => $this->destroyReady($id)); }
        catch (LockedException) { return new JSONResponse(['message' => $this->l->t('The archive is currently in use. Try again when the operation has finished.')], 409); }
        catch (\RuntimeException $error) { return new JSONResponse(['message' => $this->l->t($error->getMessage())], 503); }
    }

    private function destroyReady(int $id): JSONResponse {
        try {
            $result = $this->mounts->withExclusive($id, function () use ($id): int|JSONResponse {
                $row = $this->mounts->find($id);
                if ($row === null || (int)($row['preview_depth'] ?? 0) > 0) {
                    throw new NotFoundException('Connection not found.');
                }
                $parent = $this->nodeById((int)$row['parent_id']);
                try {
                    $source = $this->nodeById((int)$row['source_id']);
                } catch (NotFoundException $error) {
                    if (!$this->canDisconnectUnavailable($parent)) {
                        throw $error;
                    }
                    $source = null;
                }
                if ($source !== null && !$this->canManage($parent, $source)) {
                    return new JSONResponse(['message' => $this->l->t('You cannot disconnect this shared archive.')], 403);
                }
                $this->mounts->delete($id);
                return (int)$row['parent_id'];
            });
            if ($result instanceof JSONResponse) {
                return $result;
            }
            $this->invalidator->invalidate($result, $this->userSession->getUser());
            return new JSONResponse(['disconnected' => true]);
        } catch (NotFoundException) {
            return new JSONResponse(['message' => $this->l->t('Connection not found.')], 404);
        } catch (LockedException) {
            return new JSONResponse(['message' => $this->l->t('The archive is currently in use. Try again when the operation has finished.')], 409);
        } catch (\RuntimeException $error) {
            return new JSONResponse(['message' => $this->l->t($error->getMessage())], 409);
        }
    }
}
