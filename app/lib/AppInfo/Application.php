<?php

declare(strict_types=1);

namespace OCA\ArchiveTools\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Config\IMountProviderCollection;
use OCA\ArchiveTools\Mount\MountProvider;
use OCA\ArchiveTools\Listener\FolderMovedListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Share\Events\ShareMovedEvent;
use OCA\ArchiveTools\Notification\Notifier;
use OCA\ArchiveTools\Listener\FilesListener;
use OCA\Files\Event\LoadAdditionalScriptsEvent;

class Application extends App implements IBootstrap {
    public const APP_ID = 'archive_tools';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerNotifierService(Notifier::class);
        $context->registerEventListener(LoadAdditionalScriptsEvent::class, FilesListener::class);
        $context->registerEventListener(NodeRenamedEvent::class, FolderMovedListener::class);
        $context->registerEventListener(ShareMovedEvent::class, FolderMovedListener::class, -100);
    }

    public function boot(IBootContext $context): void {
        $context->injectFn(static function (IMountProviderCollection $providers, MountProvider $provider): void {
            $providers->registerProvider($provider);
        });
    }
}
