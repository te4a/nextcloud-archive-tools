<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Controller;

use OCA\ArchiveTools\Archive\ArchiveReader;
use OCA\ArchiveTools\Archive\ArchivePasswordRequiredException;
use OCA\ArchiveTools\Archive\OperationBudget;
use OCA\ArchiveTools\Service\ArchiveService;
use OCA\ArchiveTools\Service\ArchiveChangedException;
use OCA\ArchiveTools\Service\ExtractionService;
use OCA\ArchiveTools\Service\MountRepository;
use OCA\ArchiveTools\Service\LifecycleService;
use OCA\ArchiveTools\Service\AdmissionService;
use OCA\ArchiveTools\Service\ResourceLimitException;
use OCA\ArchiveTools\Storage\LeasedStream;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\AlreadyExistsException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Security\ICrypto;

final class ArchiveController extends Controller {
    private const PREVIEWS = 'archive_tools_previews';

    public function __construct(
        IRequest $request,
        private IUserSession $session,
        private ArchiveService $archives,
        private ExtractionService $extraction,
        private ArchiveReader $reader,
        private MountRepository $mounts,
        private IL10N $l,
        private LifecycleService $lifecycle,
        private ISession $browserSession,
        private ICrypto $crypto,
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
    public function download(int $sourceId, string $member, #[\SensitiveParameter] ?string $password = null, ?string $expectedEtag = null, ?string $downloadId = null): Response {
        return $this->streamMember($sourceId, $member, $password, $expectedEtag, downloadId: $downloadId);
    }

    #[NoAdminRequired]
    #[UseSession]
    public function preparePreview(int $sourceId, string $member, string $expectedEtag, #[\SensitiveParameter] ?string $password = null): JSONResponse {
        try {
            AdmissionService::validatePassword($password);
            if ($this->archives->previewMime($member) === null) {
                return new JSONResponse(['message' => $this->l->t('This file type cannot be previewed.')], 415);
            }
            $owner = $this->owner();
            $this->archives->currentEtag($this->archives->source($owner, $sourceId), $expectedEtag);
            $now = time();
            $tickets = array_filter(
                $this->browserSession->get(self::PREVIEWS) ?? [],
                static fn (array $ticket): bool => $ticket['expires'] > $now,
            );
            $ticket = bin2hex(random_bytes(24));
            $tickets[$ticket] = [
                'sourceId' => $sourceId, 'member' => $member, 'expectedEtag' => $expectedEtag,
                'secret' => $password !== null && $password !== '' ? $this->crypto->encrypt($password) : null,
                'owner' => $owner, 'expires' => $now + 3600,
            ];
            $this->browserSession->set(self::PREVIEWS, array_slice($tickets, -12, preserve_keys: true));
            return new JSONResponse(['ticket' => $ticket], 201, ['Cache-Control' => 'no-store']);
        } catch (NotFoundException) {
            return new JSONResponse(['message' => $this->l->t('Archive not found.')], 404);
        } catch (ArchiveChangedException $error) {
            return new JSONResponse(['message' => $this->l->t($error->getMessage())], 409);
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            return new JSONResponse(['message' => $this->l->t($error->getMessage())], 422);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function preview(string $ticket): Response {
        return $this->servePreview($ticket, false);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function previewHead(string $ticket): Response {
        return $this->servePreview($ticket, true);
    }

    private function servePreview(string $ticket, bool $head): Response {
        $entry = preg_match('/\A[a-f0-9]{48}\z/', $ticket) ? ($this->browserSession->get(self::PREVIEWS)[$ticket] ?? null) : null;
        if ($entry === null || $entry['expires'] <= time() || $entry['owner'] !== $this->owner()) {
            return new JSONResponse(['message' => $this->l->t('This preview has expired. Open the file again.')], 404, ['Cache-Control' => 'no-store']);
        }
        $mime = $this->archives->previewMime($entry['member']);
        if ($mime === null) {
            return new JSONResponse(['message' => $this->l->t('This file type cannot be previewed.')], 415);
        }
        $password = $entry['secret'] === null ? null : $this->crypto->decrypt($entry['secret']);
        return $this->streamMember($entry['sourceId'], $entry['member'], $password, $entry['expectedEtag'], $mime, $head);
    }

    private function streamMember(int $sourceId, string $member, #[\SensitiveParameter] ?string $password, ?string $expectedEtag, ?string $previewMime = null, bool $head = false, ?string $downloadId = null): Response {
        $source = null;
        $locked = false;
        $leases = [];
        $activity = null;
        $raw = null;
        try {
            AdmissionService::validatePassword($password);
            $activity = $this->lifecycle->enter();
            $source = $this->archives->source($this->owner(), $sourceId);
            $source->lock(ILockingProvider::LOCK_SHARED);
            $locked = true;
            $this->archives->currentEtag($source, $expectedEtag);
            $leases = $this->mounts->beginSourceActivity($sourceId);
            $path = $this->archives->localPath($source);
            $budget = $previewMime === null ? null : new OperationBudget();
            $index = $this->reader->inspect($path, $password, $budget, allowLargeZip: true);
            if (!isset($index['entries'][$member]) || $index['entries'][$member]['type'] !== 'file') {
                throw new \InvalidArgumentException('Select a file inside the archive.');
            }
            $entry = $index['entries'][$member];
            $size = (int)$entry['size'];
            $headers = [
                'Content-Type' => $previewMime ?? 'application/octet-stream',
                'Content-Disposition' => ($previewMime === null ? 'attachment' : 'inline')
                    . "; filename=\"archive-entry\"; filename*=UTF-8''" . rawurlencode(basename($member)),
                'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
            ];
            $status = 200;
            $start = 0;
            $end = $size - 1;
            if ($previewMime !== null) {
                $headers['Accept-Ranges'] = 'bytes';
                $headers['Referrer-Policy'] = 'no-referrer';
                $range = $this->request->getHeader('Range');
                if (preg_match('/\Abytes=(\d*)-(\d*)\z/D', $range, $matches)) {
                    [$start, $end] = $this->memberRange($size, $matches[1], $matches[2]);
                    if ($start > $end || $start >= $size) {
                        return new Response(416, ['Content-Range' => 'bytes */' . $size, 'Cache-Control' => 'no-store']);
                    }
                    $status = 206;
                    $headers['Content-Range'] = "bytes $start-$end/$size";
                }
            }
            $length = $end - $start + 1;
            $headers['Content-Length'] = (string)$length;
            if ($head) {
                return new Response($status, $headers);
            }
            $raw = $this->reader->stream($path, $entry['originalName'], $password, $budget);
            $lastRenew = microtime(true);
            $renew = function () use (&$lastRenew, $leases): void {
                if (microtime(true) - $lastRenew >= 1) { $this->mounts->renewSourceActivity($leases); $lastRenew = microtime(true); }
            };
            // Archive streams are sequential. Advance in bounded reads when a viewer requests an offset.
            for ($offset = 0; $offset < $start;) {
                $offset += strlen($this->readMemberChunk($raw, min(65536, $start - $offset)));
                $renew();
            }
            // Wait for the decoder before signalling that the browser can take over.
            $prefix = $length > 0 ? $this->readMemberChunk($raw, min(8192, $length)) : '';
            $tracked = LeasedStream::wrap($raw, $renew, function () use ($source, $leases, $activity): void {
                try { $this->mounts->endSourceActivity($leases); }
                finally {
                    try { $source->unlock(ILockingProvider::LOCK_SHARED); }
                    finally { $activity->close(); }
                }
            }, $prefix, $previewMime !== null ? $length : null);
            $raw = null;
            $locked = false;
            $leases = [];
            $activity = null;
            $response = new StreamResponse($tracked, $status, $headers);
            if ($downloadId !== null && preg_match('/\A[a-f0-9]{32}\z/', $downloadId)) {
                $cookie = 'archive_tools_download_' . $downloadId . '=1; Path=/; Max-Age=300; SameSite=Lax';
                if ($this->request->getServerProtocol() === 'https') {
                    $cookie .= '; Secure';
                }
                $response->addHeader('Set-Cookie', $cookie);
            }
            return $response;
        } catch (NotFoundException) {
            return new JSONResponse(['message' => $this->l->t('Archive not found.')], 404);
        } catch (ArchiveChangedException $error) {
            return new JSONResponse(['message' => $this->l->t($error->getMessage())], 409);
        } catch (LockedException) {
            return $this->busy();
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            return new JSONResponse(['message' => $this->l->t($error->getMessage())], 422);
        } finally {
            try {
                if (is_resource($raw)) {
                    fclose($raw);
                }
            } finally {
                if ($locked) {
                    try { $this->mounts->endSourceActivity($leases); }
                    finally { $source->unlock(ILockingProvider::LOCK_SHARED); }
                }
                $activity?->close();
            }
        }
    }

    /** @param resource $stream */
    private function readMemberChunk($stream, int $length): string {
        $bytes = fread($stream, $length);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('The archive member could not be read.');
        }
        return $bytes;
    }

    private function memberRange(int $size, string $from, string $to): array {
        if ($from === '') {
            $start = $to === '' ? $size : max(0, $size - (int)$to);
            return [$start, $size - 1];
        }
        return [(int)$from, $to === '' ? $size - 1 : min((int)$to, $size - 1)];
    }

    private function busy(): JSONResponse {
        return new JSONResponse(['message' => $this->l->t('The archive is currently in use. Try again when the operation has finished.')], 409);
    }
}
