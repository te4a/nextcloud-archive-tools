<?php

declare(strict_types=1);

namespace OCA\ArchiveTools\AppInfo;

use OCA\ArchiveTools\Archive\ArchiveReader;
use OCA\ArchiveTools\Archive\ZipReader;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Config\IMountProviderCollection;
use OCP\IConfig;
use OCP\IContainer;
use OCA\ArchiveTools\Mount\MountProvider;
use OCA\ArchiveTools\Listener\FolderMovedListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Share\Events\ShareMovedEvent;
use OCP\Share\Events\ShareDeletedEvent;
use OCA\ArchiveTools\Service\ShareDeletionScope;
use OCA\ArchiveTools\Notification\Notifier;
use OCA\ArchiveTools\Listener\FilesListener;
use OCA\Files\Event\LoadAdditionalScriptsEvent;

class Application extends App implements IBootstrap {
    public const APP_ID = 'archive_tools';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerService(ZipReader::class, static fn (): ZipReader => new ZipReader());
        $context->registerService(ShareDeletionScope::class, static fn (): ShareDeletionScope => new ShareDeletionScope());
        $context->registerService(ArchiveReader::class, static function (IContainer $container): ArchiveReader {
            $config = $container->get(IConfig::class);
            return new ArchiveReader(
                zip: $container->get(ZipReader::class),
                largeZip: $config->getSystemValue('archive_tools_large_zip', false) === true ? ZipReader::largeIndex() : null,
            );
        });
        $context->registerNotifierService(Notifier::class);
        $context->registerEventListener(LoadAdditionalScriptsEvent::class, FilesListener::class);
        $context->registerEventListener(NodeRenamedEvent::class, FolderMovedListener::class);
        $context->registerEventListener(ShareMovedEvent::class, FolderMovedListener::class, -100);
    }

    public function boot(IBootContext $context): void {
        $context->injectFn(static function (IMountProviderCollection $providers, MountProvider $provider, IEventDispatcher $events, ShareDeletionScope $shareDeletion): void {
            $providers->registerProvider($provider);
            $events->addListener(ShareDeletedEvent::class, $shareDeletion->begin(...), PHP_INT_MAX);
            $events->addListener(ShareDeletedEvent::class, $shareDeletion->end(...), PHP_INT_MIN);
        });
    }
}
