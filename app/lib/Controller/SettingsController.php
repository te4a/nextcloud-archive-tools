<?php

declare(strict_types=1);

namespace OCA\ArchiveTools\Controller;

use InvalidArgumentException;
use OCA\ArchiveTools\AppInfo\Application;
use OCA\ArchiveTools\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/** Admin access and CSRF protection are enforced by AppFramework defaults. */
class SettingsController extends Controller {
    public function __construct(
        IRequest $request,
        private SettingsService $settings,
        private IL10N $l10n,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    public function index(): DataResponse {
        return new DataResponse($this->settings->getSettings());
    }

    public function update(mixed $openByClick, mixed $idleTimeoutMinutes, mixed $allowPin, mixed $jobsRetentionDays = null): DataResponse {
        try {
            return new DataResponse($this->settings->update($openByClick, $idleTimeoutMinutes, $allowPin, $jobsRetentionDays));
        } catch (InvalidArgumentException $exception) {
            return new DataResponse([
                'message' => $this->l10n->t($exception->getMessage()),
            ], Http::STATUS_BAD_REQUEST);
        } catch (\OCP\Lock\LockedException) {
            return new DataResponse(['message' => $this->l10n->t('The archive is currently in use. Try again when the operation has finished.')], Http::STATUS_SERVICE_UNAVAILABLE);
        } catch (\RuntimeException $exception) {
            return new DataResponse(['message' => $this->l10n->t($exception->getMessage())], Http::STATUS_SERVICE_UNAVAILABLE);
        }
    }
}
