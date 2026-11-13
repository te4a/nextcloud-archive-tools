<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Controller;

use Icewind\Streams\CallbackWrapper;
use OCA\ArchiveTools\Archive\ArchiveReader;
use OCA\ArchiveTools\Archive\ArchivePasswordRequiredException;
use OCA\ArchiveTools\Service\ArchiveService;
use OCA\ArchiveTools\Service\ArchiveChangedException;
use OCA\ArchiveTools\Service\ExtractionService;
use OCA\ArchiveTools\Service\MountRepository;
use OCA\ArchiveTools\Service\LifecycleService;
use OCA\ArchiveTools\Service\AdmissionService;
use OCA\ArchiveTools\Service\ResourceLimitException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\AlreadyExistsException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

final class ArchiveController extends Controller {
    public function __construct(
        IRequest $request,
        private IUserSession $session,
        private ArchiveService $archives,
        private ExtractionService $extraction,
        private ArchiveReader $reader,
        private MountRepository $mounts,
        private IL10N $l,
        private LifecycleService $lifecycle,
    ) { parent::__construct('archive_tools', $request); }

    private function owner(): string {
        return $this->session->getUser()->getUID();
    }

    #[NoAdminRequired]
    public function inspect(int $sourceId, #[\SensitiveParameter] ?string $password = null, ?string $knownEtag = null): JSONResponse {
        $activity = null;
        try { AdmissionService::validatePassword($password); $activity = $this->lifecycle->enter(); return new JSONResponse($this->archives->inspect($this->owner(), $sourceId, $password, $knownEtag)); }
        catch (ArchivePasswordRequiredException $error) { return new JSONResponse(['message' => $this->l->t($error->getMessage()), 'code' => 'password_required'], 422); }
        catch (NotFoundException) { return new JSONResponse(['message' => $this->l->t('Archive not found.')], 404); }
        catch (LockedException) { return $this->busy(); }
        catch (ResourceLimitException $error) { return new JSONResponse(['message' => $this->l->t($error->getMessage()), 'code' => 'resource_limit'], 429); }
        catch (\InvalidArgumentException|\RuntimeException $error) { return new JSONResponse(['message' => $this->l->t($error->getMessage())], 422); }
        finally { $activity?->close(); }
    }

    #[NoAdminRequired]
    public function browseZip(int $sourceId, string $path = '', #[\SensitiveParameter] ?string $password = null, ?string $expectedEtag = null): JSONResponse {
        $activity = null;
        try {
            AdmissionService::validatePassword($password);
            $activity = $this->lifecycle->enter();
            $directory = $this->archives->browseZip($this->owner(), $sourceId, $path, $password, $expectedEtag);
            return $directory === null ? new JSONResponse(['code' => 'not_zip'], 415) : new JSONResponse($directory);
        } catch (ArchivePasswordRequiredException $error) {
            return new JSONResponse(['message' => $this->l->t($error->getMessage()), 'code' => 'password_required'], 422);
        } catch (NotFoundException) {
            return new JSONResponse(['message' => $this->l->t('Archive not found.')], 404);
        } catch (\OutOfBoundsException $error) {
            return new JSONResponse(['message' => $this->l->t($error->getMessage())], 404);
        } catch (ArchiveChangedException $error) {
            return new JSONResponse(['message' => $this->l->t($error->getMessage()), 'code' => 'archive_changed'], 409);
        } catch (LockedException) {
            return $this->busy();
        } catch (ResourceLimitException $error) {
            return new JSONResponse(['message' => $this->l->t($error->getMessage()), 'code' => 'resource_limit'], 429);
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            return new JSONResponse(['message' => $this->l->t($error->getMessage())], 422);
        } finally {
            $activity?->close();
        }
    }

    #[NoAdminRequired]
    public function extract(int $sourceId, ?int $destinationId = null, string $folderName = '', array $members = [], #[\SensitiveParameter] ?string $password = null, ?string $expectedEtag = null): JSONResponse {
        try { return new JSONResponse($this->extraction->schedule($this->owner(), $sourceId, $destinationId, $folderName, $members, $password, $expectedEtag), 202); }
        catch (NotFoundException) { return new JSONResponse(['message' => $this->l->t('Archive or destination folder not found.')], 404); }
        catch (NotPermittedException $error) { return new JSONResponse(['message' => $this->l->t($error->getMessage())], 403); }
        catch (AlreadyExistsException $error) { return new JSONResponse(['message' => $this->l->t($error->getMessage())], 409); }
        catch (ArchiveChangedException $error) { return new JSONResponse(['message' => $this->l->t($error->getMessage())], 409); }
        catch (LockedException) { return $this->busy(); }
        catch (ResourceLimitException $error) { return new JSONResponse(['message' => $this->l->t($error->getMessage()), 'code' => 'resource_limit'], 429); }
        catch (\InvalidArgumentException|\RuntimeException $error) { return new JSONResponse(['message' => $this->l->t($error->getMessage())], 422); }
    }

    #[NoAdminRequired]
    public function download(int $sourceId, string $member, #[\SensitiveParameter] ?string $password = null, ?string $expectedEtag = null): StreamResponse|JSONResponse {
        $source = null;
        $locked = false;
        $leases = [];
        $activity = null;
        try {
            AdmissionService::validatePassword($password);
            $activity = $this->lifecycle->enter();
            $source = $this->archives->source($this->owner(), $sourceId);
            $source->lock(ILockingProvider::LOCK_SHARED);
            $locked = true;
            $this->archives->currentEtag($source, $expectedEtag);
            $leases = $this->mounts->beginSourceActivity($sourceId);
            $path = $this->archives->localPath($source);
            $index = $this->reader->inspect($path, $password, allowLargeZip: true);
            if (!isset($index['entries'][$member]) || $index['entries'][$member]['type'] !== 'file') {
                throw new \InvalidArgumentException('Select a file inside the archive.');
            }
            $entry = $index['entries'][$member];
            $raw = $this->reader->stream($path, $entry['originalName'], $password);
            $lastRenew = microtime(true);
            $tracked = CallbackWrapper::wrap($raw, function (int $bytes) use (&$lastRenew, $leases): void {
                if (microtime(true) - $lastRenew >= 1) { $this->mounts->renewSourceActivity($leases); $lastRenew = microtime(true); }
            }, null, function () use ($source, $leases, $activity): void {
                try { $this->mounts->endSourceActivity($leases); }
                finally {
                    try { $source->unlock(ILockingProvider::LOCK_SHARED); }
                    finally { $activity->close(); }
                }
            });
            $locked = false;
            $leases = [];
            $activity = null;
            return new StreamResponse($tracked, 200, [
                'Content-Type' => 'application/octet-stream', 'Content-Length' => (string)$entry['size'],
                'Content-Disposition' => "attachment; filename=\"archive-entry\"; filename*=UTF-8''" . rawurlencode(basename($member)),
                'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (NotFoundException) {
            return new JSONResponse(['message' => $this->l->t('Archive not found.')], 404);
        } catch (ArchiveChangedException $error) {
            return new JSONResponse(['message' => $this->l->t($error->getMessage())], 409);
        } catch (LockedException) {
            return $this->busy();
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            return new JSONResponse(['message' => $this->l->t($error->getMessage())], 422);
        } finally {
            if ($locked) {
                try { $this->mounts->endSourceActivity($leases); }
                finally { $source->unlock(ILockingProvider::LOCK_SHARED); }
            }
            $activity?->close();
        }
    }

    private function busy(): JSONResponse {
        return new JSONResponse(['message' => $this->l->t('The archive is currently in use. Try again when the operation has finished.')], 409);
    }
}
