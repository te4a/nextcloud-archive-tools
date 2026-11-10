<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Listener;

use OCA\ArchiveTools\Service\SettingsService;
use OCA\ArchiveTools\Archive\ArchiveReader;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

final class FilesListener implements IEventListener {
    public function __construct(private SettingsService $settings, private IInitialState $initialState, private ArchiveReader $reader) {}

    public function handle(Event $event): void {
        if ($event instanceof LoadAdditionalScriptsEvent) {
            $this->initialState->provideInitialState('settings', $this->settings->getSettings());
            $this->initialState->provideInitialState('archiveFormats', $this->reader->supportedFormats());
            // Custom views must exist before Files mounts its navigation.
            Util::addInitScript('archive_tools', 'files');
            Util::addStyle('archive_tools', 'archive-tools');
        }
    }
}
