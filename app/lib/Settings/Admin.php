<?php

declare(strict_types=1);

namespace OCA\ArchiveTools\Settings;

use OCA\ArchiveTools\AppInfo\Application;
use OCA\ArchiveTools\Service\SettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
    public function __construct(
        private SettingsService $settings,
        private IInitialState $initialState,
    ) {
    }

    public function getForm(): TemplateResponse {
        $this->initialState->provideInitialState('settings', $this->settings->getSettings());
        Util::addScript(Application::APP_ID, 'admin');
        Util::addStyle(Application::APP_ID, 'admin');

        return new TemplateResponse(Application::APP_ID, 'admin', [], 'blank');
    }

    public function getSection(): string {
        return 'additional';
    }

    public function getPriority(): int {
        return 50;
    }
}
