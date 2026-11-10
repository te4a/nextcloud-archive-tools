<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Service;

use Icewind\Streams\CallbackWrapper;
use OCA\ArchiveTools\Archive\ArchiveReader;
use OCA\ArchiveTools\Archive\OperationBudget;
use OCA\ArchiveTools\BackgroundJob\ExtractJob;
use OCA\ArchiveTools\Storage\ExtractionLockStorage;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

final class ExtractionCancelled extends \RuntimeException {}

final class ExtractionService {
    public function __construct(
        private ArchiveService $archives,
        private ArchiveReader $reader,
        private JobRepository $jobs,
        private IJobList $queue,
        private ICrypto $crypto,
        private ILockingProvider $locks,
        private MountRepository $mounts,
        private IUserSession $session,
        private NotificationService $notifications,
        private LoggerInterface $logger,
        private LifecycleService $lifecycle,
        private AdmissionService $admission,
    ) {}

    public function schedule(string $owner, int $sourceId, ?int $destinationId, string $folderName, array $members, #[\SensitiveParameter] ?string $password, ?string $expectedEtag = null): array {
        AdmissionService::validatePassword($password);
        $activity = $this->lifecycle->enter();
        try { return $this->scheduleActive($owner, $sourceId, $destinationId, $folderName, $members, $password, $expectedEtag); }
        finally { $activity->close(); }
    }

    private function scheduleActive(string $owner, int $sourceId, ?int $destinationId, string $folderName, array $members, #[\SensitiveParameter] ?string $password, ?string $expectedEtag): array {
        $source = $this->archives->source($owner, $sourceId);
        $destination = $this->archives->destination($owner, $destinationId ?? $source->getParent()->getId());
        $folderName = $this->archives->folderName($folderName, $source);
        $source->lock(ILockingProvider::LOCK_SHARED);
        $leases = [];
        try {
            $sourceEtag = $this->archives->currentEtag($source, $expectedEtag);
            $leases = $this->mounts->beginSourceActivity($sourceId);
            $path = $this->archives->localPath($source);
            $index = $this->reader->inspect($path, $password);
            $plan = $this->archives->select($index, $members);
            $this->archives->probePassword($path, $plan, $password);
            $files = 0;
            $bytes = 0;
            foreach ($plan as $entry) { if ($entry['type'] === 'file') { $files++; $bytes += $entry['size']; } }
            $key = hash('sha256', json_encode([$owner, $sourceId, $sourceEtag, $destination->getId(), $folderName, array_keys($plan)], JSON_THROW_ON_ERROR));
            if ($existing = $this->jobs->findActive($key)) { return JobRepository::dto($existing); }
            if ($destination->nodeExists($folderName)) { throw new \OCP\Files\AlreadyExistsException('The extraction folder already exists. Choose a different name.'); }
            $planJson = json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $encryptedSecret = $password === null ? null : $this->crypto->encrypt($password);
            return $this->lifecycle->write(function () use ($owner, $sourceId, $source, $sourceEtag, $destination, $folderName, $planJson, $encryptedSecret, $files, $bytes, $key): array {
            // The durable writer barrier serializes simultaneous submissions.
            if ($existing = $this->jobs->findActive($key)) { return JobRepository::dto($existing); }
            $this->admission->assertJob($owner, $planJson, $encryptedSecret);
            $now = time();
            try {
                $id = $this->jobs->create([
                    'owner_id' => $owner, 'source_id' => $sourceId, 'source_name' => $source->getName(), 'source_etag' => $sourceEtag,
                    'destination_id' => $destination->getId(), 'folder_name' => $folderName,
                    'plan_json' => $planJson,
                    'secret' => $encryptedSecret,
                    'active_key' => $key, 'state' => 'queued', 'cancel_requested' => 0,
                    'files_total' => $files, 'files_done' => 0, 'bytes_total' => $bytes, 'bytes_done' => 0,
                    'created_at' => $now, 'updated_at' => $now, 'target_id' => null, 'message' => null,
                ]);
            } catch (\OCP\DB\Exception $error) {
                if ($error->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION && ($existing = $this->jobs->findActive($key))) {
                    return JobRepository::dto($existing);
                }
                throw $error;
            }
            try { $this->queue->add(ExtractJob::class, ['jobId' => $id]); }
            catch (\Throwable $error) {
                $this->jobs->update($id, ['state' => 'failed', 'active_key' => null, 'secret' => null, 'message' => 'The extraction job could not be queued.']);
                throw new \RuntimeException('The extraction job could not be queued.', previous: $error);
            }
            return JobRepository::dto($this->jobs->find($id));
            });
        } finally {
            $this->mounts->endSourceActivity($leases);
            $source->unlock(ILockingProvider::LOCK_SHARED);
        }
    }

    public function failUnavailableUser(int $id): void {
        $row = $this->jobs->find($id);
        if ($row !== null && $row['state'] === 'queued') {
            $this->jobs->update($id, ['state' => 'failed', 'active_key' => null, 'secret' => null, 'message' => 'The job owner no longer exists.']);
        }
    }

    public function reconcile(): void {
        $this->lifecycle->write(function (): void {
        foreach ($this->jobs->recoverInterrupted() as $row) {
            try { $this->notifications->jobFinished($row['owner_id'], (int)$row['id'], $row['source_name'], 'failed', $row['target_id'] === null ? null : (int)$row['target_id']); }
            catch (\Throwable) { /* Job state remains authoritative. */ }
        }
        foreach ($this->jobs->pending() as $row) {
            $this->queue->add(ExtractJob::class, ['jobId' => (int)$row['id']]);
        }
        });
    }

    public function run(int $id, ?OperationBudget $budget = null): void {
        $activity = $this->lifecycle->enter();
        try { $this->runActive($id, $budget); }
        finally { $activity->close(); }
    }

    private function runActive(int $id, ?OperationBudget $budget): void {
        $row = $this->jobs->find($id);
        if ($row === null || !$this->lifecycle->write(fn (): bool => $this->jobs->claim($id))) { return; }
        $budget ??= new OperationBudget();
        $source = null;
        $destination = null;
        $sourceLocked = false;
        $destinationLocked = false;
        $destinationStorage = null;
        $destinationMount = null;
        $destinationPath = null;
        $outputPath = null;
        $outputLocked = false;
        $rollbackSafe = false;
        $reservationStorage = null;
        $output = null;
        $leases = [];
        $state = 'failed';
        $message = 'Extraction failed. No completed output was published.';
        try {
            $budget->check();
            if ($this->session->getUser()?->getUID() !== $row['owner_id']) { throw new \RuntimeException('The extraction user context is unavailable.'); }
            $this->checkCancelled($id);
            $source = $this->archives->source($row['owner_id'], (int)$row['source_id']);
            $destination = $this->archives->destination($row['owner_id'], (int)$row['destination_id']);
            $source->getStorage()->acquireLock($source->getInternalPath(), ILockingProvider::LOCK_SHARED, $this->locks);
            $sourceLocked = true;
            $leases = $this->mounts->beginSourceActivity((int)$row['source_id']);
            if (!hash_equals($row['source_etag'], $this->archives->currentEtag($source))) { throw new \RuntimeException('The source archive changed before extraction started.'); }
            $password = $row['secret'] === null ? null : $this->crypto->decrypt($row['secret']);
            $localPath = $this->archives->localPath($source);
            $plan = json_decode($row['plan_json'], true, 512, JSON_THROW_ON_ERROR);
            $budget->check();
            // Lock the destination parent before reservation and throughout
            // rollback. Other requests cannot insert files into partial output.
            $destinationMount = $destination->getMountPoint();
            $destinationStorage = $destinationMount->getStorage();
            if ($destinationStorage === null) { throw new \RuntimeException('The destination storage is unavailable.'); }
            $destinationPath = $destinationMount->getInternalPath($destination->getPath());
            $destinationStorage->acquireLock($destinationPath, ILockingProvider::LOCK_EXCLUSIVE, $this->locks);
            $destinationLocked = true;
            $reservationStorage = new ExtractionLockStorage($destinationStorage, $destinationPath);
            $destinationMount->wrapStorage(static fn () => $reservationStorage);
            if ($destination->nodeExists($row['folder_name'])) { throw new \RuntimeException('The extraction folder already exists. Choose a different name.'); }
            $this->checkCancelled($id);
            $output = $destination->newFolder($row['folder_name']);
            // The user root is omitted from View's ancestor locks. Reserve the
            // created output too, so other requests cannot edit partial files
            // even when extraction targets that root.
            $outputPath = $destinationMount->getInternalPath($output->getPath());
            $destinationStorage->acquireLock($outputPath, ILockingProvider::LOCK_EXCLUSIVE, $this->locks);
            $outputLocked = true;
            $reservationStorage->reserve($outputPath);
            // A request could have reached the just-created folder before its
            // reservation was acquired. Keep any such files for review.
            if ($output->getDirectoryListing() !== []) { throw new \RuntimeException('The new extraction folder was modified by another request.'); }
            $rollbackSafe = true;
            $this->jobs->update($id, ['target_id' => $output->getId()]);
            $directories = ['' => $output];
            $filesDone = 0;
            $bytesDone = 0;
            $lastProgress = microtime(true);
            foreach ($plan as $member => $entry) {
                if ($entry['type'] !== 'dir') { continue; }
                $budget->check();
                $this->checkCancelled($id);
                $this->mounts->renewSourceActivity($leases);
                $this->directory($directories, (string)$member);
            }
            $streams = $this->reader->streams($localPath, $plan, $password, $budget);
            try {
                foreach ($streams as $member => $stream) {
                    $member = (string)$member;
                    $entry = $plan[$member];
                    $budget->check();
                    $this->checkCancelled($id);
                    $this->mounts->renewSourceActivity($leases);
                    $this->jobs->update($id, []);
                    $folder = $this->directory($directories, dirname($member) === '.' ? '' : dirname($member));
                    $fileRead = 0;
                    $tracked = CallbackWrapper::wrap($stream, function (int $count) use ($id, &$fileRead, &$lastProgress, &$bytesDone, &$filesDone, &$leases, $entry, $budget): void {
                        $budget->check();
                        $fileRead += $count;
                        if ($fileRead > $entry['size']) { throw new \RuntimeException('The archive member exceeds its declared size.'); }
                        if (microtime(true) - $lastProgress >= 0.25) {
                            $this->checkCancelled($id);
                            $this->mounts->renewSourceActivity($leases);
                            $this->jobs->update($id, ['files_done' => $filesDone, 'bytes_done' => $bytesDone + $fileRead]);
                            $lastProgress = microtime(true);
                        }
                    });
                    try {
                        $file = $folder->newFile(basename($member), $tracked);
                        if ($fileRead !== (int)$entry['size'] || $file->getSize() !== (int)$entry['size']) { throw new \RuntimeException('The extracted file is incomplete.'); }
                    } catch (\Throwable $writeError) {
                        // Nextcloud 35's resource-based View::file_put_contents()
                        // leaves its file lock and ancestor read locks acquired if
                        // the input stream throws. Balance precisely that write;
                        // the enclosing destination-parent exclusive lock stays
                        // held so nobody can add data to our partial output.
                        try {
                            (new \OC\Files\View('/'))->unlockFile(
                                $folder->getPath() . '/' . basename($member),
                                ILockingProvider::LOCK_EXCLUSIVE,
                            );
                        } catch (\Throwable $unlockError) {
                            $this->logger->warning('An interrupted archive write could not be unlocked.', [
                                'app' => 'archive_tools', 'jobId' => $id, 'exceptionClass' => $unlockError::class,
                                'sourceFile' => basename($unlockError->getFile()), 'sourceLine' => $unlockError->getLine(),
                            ]);
                        }
                        throw $writeError;
                    } finally { if (is_resource($tracked)) { fclose($tracked); } }
                    $filesDone++;
                    $bytesDone += $fileRead;
                    $this->jobs->update($id, ['files_done' => $filesDone, 'bytes_done' => $bytesDone]);
                }
            } finally { unset($streams); }
            $this->checkCancelled($id);
            $budget->check();
            $this->jobs->update($id, ['state' => 'succeeded', 'secret' => null, 'active_key' => null, 'message' => null]);
            $state = 'succeeded';
        } catch (\Throwable $error) {
            $cancelled = $error instanceof ExtractionCancelled;
            $state = $cancelled ? 'cancelled' : 'failed';
            $message = $cancelled ? 'Extraction was cancelled.' : $this->safeFailureMessage($error);
            if ($output !== null) {
                if (!$rollbackSafe) {
                    $state = 'failed';
                    $message = 'Extraction stopped, but partial output could not be removed. Review the destination folder.';
                } else {
                    try {
                        $output->delete();
                        $output = null;
                    } catch (\Throwable $cleanupError) {
                        $state = 'failed';
                        $message = 'Extraction stopped, but partial output could not be removed. Review the destination folder.';
                        $this->logger->warning('Partial archive output could not be removed.', [
                            'app' => 'archive_tools', 'jobId' => $id, 'exceptionClass' => $cleanupError::class,
                            'sourceFile' => basename($cleanupError->getFile()), 'sourceLine' => $cleanupError->getLine(),
                        ]);
                    }
                }
            }
            $this->jobs->update($id, ['state' => $state, 'secret' => null, 'active_key' => null, 'message' => $message, 'target_id' => $output?->getId()]);
            $this->logger->warning('An archive extraction did not complete.', [
                'app' => 'archive_tools', 'jobId' => $id, 'exceptionClass' => $error::class,
                'sourceFile' => basename($error->getFile()), 'sourceLine' => $error->getLine(),
            ]);
        } finally {
            try {
                if ($reservationStorage !== null) {
                    $reservationStorage->deactivate();
                    $destinationMount->wrapStorage(static fn () => $destinationStorage);
                }
                if ($outputLocked) { $destinationStorage->releaseLock($outputPath, ILockingProvider::LOCK_EXCLUSIVE, $this->locks); }
                if ($destinationLocked) { $destinationStorage->releaseLock($destinationPath, ILockingProvider::LOCK_EXCLUSIVE, $this->locks); }
            }
            finally {
                $this->mounts->endSourceActivity($leases);
                if ($sourceLocked) { $source->getStorage()->releaseLock($source->getInternalPath(), ILockingProvider::LOCK_SHARED, $this->locks); }
            }
        }
        try { $this->notifications->jobFinished($row['owner_id'], $id, $row['source_name'], $state, $output?->getId()); }
        catch (\Throwable $error) { $this->logger->warning('An archive job notification could not be delivered.', ['app' => 'archive_tools', 'jobId' => $id, 'exceptionClass' => $error::class]); }
    }

    private function directory(array &$directories, string $path): Folder {
        if (isset($directories[$path])) { return $directories[$path]; }
        $parentPath = dirname($path) === '.' ? '' : dirname($path);
        $parent = $this->directory($directories, $parentPath);
        return $directories[$path] = $parent->newFolder(basename($path));
    }

    private function checkCancelled(int $id): void {
        $row = $this->jobs->find($id);
        if ($row === null || (bool)$row['cancel_requested']) { throw new ExtractionCancelled(); }
        if ($row['state'] !== 'running') { throw new \RuntimeException('The extraction job is no longer active.'); }
    }

    private function safeFailureMessage(\Throwable $error): string {
        if ($error instanceof NotFoundException) { return 'The archive or destination folder is no longer accessible.'; }
        if ($error instanceof \RuntimeException && preg_match('/^(The ZIP |The TAR |The gzip |The archive |This archive |Multipart archives |Cannot read the ZIP |A password is required|Reading the (ZIP|TAR|gzip) |The extracted file|The source archive changed|The extraction folder already exists)/', $error->getMessage())) {
            return mb_substr($error->getMessage(), 0, 255);
        }
        return 'Extraction failed. No completed output was published.';
    }
}
