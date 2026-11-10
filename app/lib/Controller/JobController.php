<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Controller;

use OCA\ArchiveTools\Service\JobRepository;
use OCA\ArchiveTools\Service\LifecycleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

final class JobController extends Controller {
    public function __construct(IRequest $request, private JobRepository $jobs, private IUserSession $session, private IL10N $l, private LifecycleService $lifecycle) {
        parent::__construct('archive_tools', $request);
    }

    private function row(int $id): ?array {
        $row = $this->jobs->find($id);
        return $row !== null && $row['owner_id'] === $this->session->getUser()->getUID() ? $row : null;
    }

    private function dto(array $row, bool $includeMembers = true): array {
        $dto = JobRepository::dto($row, $includeMembers);
        if ($dto['message'] !== null) { $dto['message'] = $this->l->t($dto['message']); }
        return $dto;
    }

    #[NoAdminRequired]
    public function index(): JSONResponse {
        return $this->guard(function (): JSONResponse {
        $this->jobs->recoverInterrupted($this->session->getUser()->getUID());
        return new JSONResponse(array_map(fn(array $row): array => $this->dto($row, false), $this->jobs->forOwner($this->session->getUser()->getUID())));
        });
    }

    #[NoAdminRequired]
    public function show(int $id): JSONResponse {
        return $this->guard(function () use ($id): JSONResponse {
        $this->jobs->recoverInterrupted($this->session->getUser()->getUID());
        $row = $this->row($id);
        return $row === null ? new JSONResponse(['message' => $this->l->t('Job not found.')], 404) : new JSONResponse($this->dto($row));
        });
    }

    #[NoAdminRequired]
    public function cancel(int $id): JSONResponse {
        return $this->guard(function () use ($id): JSONResponse {
        if ($this->row($id) === null) { return new JSONResponse(['message' => $this->l->t('Job not found.')], 404); }
        $this->jobs->cancel($id);
        return new JSONResponse($this->dto($this->row($id)));
        });
    }

    private function guard(callable $operation): JSONResponse {
        try { return $this->lifecycle->write($operation); }
        catch (\OCP\Lock\LockedException) { return new JSONResponse(['message' => $this->l->t('The archive is currently in use. Try again when the operation has finished.')], 503); }
        catch (\RuntimeException $error) { return new JSONResponse(['message' => $this->l->t($error->getMessage())], 503); }
    }
}
