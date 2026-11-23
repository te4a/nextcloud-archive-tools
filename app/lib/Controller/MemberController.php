<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Controller;

use OCA\ArchiveTools\Service\ArchiveChangedException;
use OCA\ArchiveTools\Service\MemberOpenService;
use OCA\ArchiveTools\Service\ResourceLimitException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Lock\LockedException;

final class MemberController extends Controller {
    public function __construct(
        IRequest $request,
        private IUserSession $session,
        private MemberOpenService $members,
        private IURLGenerator $urls,
        private IL10N $l,
    ) { parent::__construct('archive_tools', $request); }

    #[NoAdminRequired]
    public function open(int $sourceId, string $member, string $expectedEtag, #[\SensitiveParameter] ?string $password = null): JSONResponse {
        try {
            $id = $this->members->open($this->session->getUser(), $sourceId, $member, $expectedEtag, $password);
            return new JSONResponse(['fileId' => (string)$id, 'url' => $this->urls->linkToRoute('files.view.showFile', ['fileid' => $id])]);
        } catch (NotFoundException) {
            return new JSONResponse(['message' => $this->l->t('Archive not found.')], 404);
        } catch (ArchiveChangedException $error) {
            return new JSONResponse(['message' => $this->l->t($error->getMessage()), 'code' => 'archive_changed'], 409);
        } catch (LockedException) {
            return new JSONResponse(['message' => $this->l->t('The archive is currently in use. Try again when the operation has finished.')], 409);
        } catch (ResourceLimitException $error) {
            return new JSONResponse(['message' => $this->l->t($error->getMessage()), 'code' => 'resource_limit'], 429);
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            return new JSONResponse(['message' => $this->l->t($error->getMessage())], 422);
        }
    }
}
