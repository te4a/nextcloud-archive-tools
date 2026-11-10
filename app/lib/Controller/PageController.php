<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Controller;

use OCA\ArchiveTools\Service\SettingsService;
use OCA\ArchiveTools\Archive\ArchiveReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

final class PageController extends Controller {
    public function __construct(IRequest $request, private SettingsService $settings, private IInitialState $initialState, private IURLGenerator $urls, private ArchiveReader $reader) {
        parent::__construct('archive_tools', $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(?string $sourceId = null): TemplateResponse|RedirectResponse {
        // Old bookmarks open the same native Files view as the ZIP action.
        if ($sourceId !== null && preg_match('/^[1-9][0-9]*$/D', $sourceId)) {
            return new RedirectResponse($this->urls->linkToRoute('files.view.indexView', ['view' => 'archive-tools-' . $sourceId]));
        }
        $this->initialState->provideInitialState('settings', $this->settings->getSettings());
        $this->initialState->provideInitialState('archiveFormats', $this->reader->supportedFormats());
        $this->initialState->provideInitialState('sourceId', $sourceId);
        Util::addScript('archive_tools', 'main');
        Util::addStyle('archive_tools', 'archive-tools');
        return new TemplateResponse('archive_tools', 'main');
    }
}
