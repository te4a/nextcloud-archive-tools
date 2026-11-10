<?php
declare(strict_types=1);

// This helper is restricted to the separate, networkless disposable instance.
if (PHP_SAPI !== 'cli' || !preg_match('/^[a-f0-9]{32}$/D', (string)getenv('ARCHIVE_TOOLS_LIFECYCLE_FIXTURE'))) { exit(2); }
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
\OC_App::registerAutoloading('archive_tools', '/var/www/html/custom_apps/archive_tools');
\OC_App::loadApps();
$server = \OC::$server;
$config = $server->get(\OCP\IConfig::class);
if ($config->getSystemValue('dbtype') !== 'sqlite3' || $config->getSystemValue('datadirectory') !== '/var/www/html/data') { exit(2); }

use OCA\ArchiveTools\AppInfo\Application;
use OCA\ArchiveTools\BackgroundJob\CleanupJob;
use OCA\ArchiveTools\BackgroundJob\ExtractJob;
use OCA\ArchiveTools\Repair\InstallApplicationState;
use OCA\ArchiveTools\Repair\RepairApplicationState;
use OCA\ArchiveTools\Service\ApplicationCleanupService;
use OCA\ArchiveTools\Service\ExtractionService;
use OCA\ArchiveTools\Service\JobRepository;
use OCA\ArchiveTools\Service\LifecycleService;
use OCA\ArchiveTools\Service\MountRepository;
use OCA\ArchiveTools\Service\SettingsService;

$db = $server->get(\OCP\IDBConnection::class);
$lifecycle = $server->get(LifecycleService::class);
$cleanup = $server->get(ApplicationCleanupService::class);
$jobs = $server->get(JobRepository::class);
$mounts = $server->get(MountRepository::class);
$queue = $server->get(\OCP\BackgroundJob\IJobList::class);
$apps = $server->get(\OCP\App\IAppManager::class);
$action = $argv[1] ?? '';
$manifestPath = '/tmp/archive-tools-lifecycle-fixture.json';
$checks = [];
function lifecycleCheck(string $name, bool $condition): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($name); }
    $checks[] = ['name' => $name, 'passed' => true];
}
function lifecycleUserFolder(): \OCP\Files\Folder {
    global $server;
    $user = $server->get(\OCP\IUserManager::class)->get('fixture_admin');
    if ($user === null) { throw new RuntimeException('The disposable fixture owner is missing.'); }
    $server->get(\OCP\IUserSession::class)->setUser($user);
    $server->get(\OCP\Files\ISetupManager::class)->setupForUser($user);
    return $server->get(\OCP\Files\IRootFolder::class)->getUserFolder('fixture_admin');
}
function lifecycleFiles(array $fixture): void {
    $folder = lifecycleUserFolder();
    lifecycleCheck('source archive bytes and file identity survive lifecycle changes',
        $folder->get('Lifecycle/source.zip')->getId() === $fixture['sourceId']
        && hash('sha256', $folder->get('Lifecycle/source.zip')->getContent()) === $fixture['sourceSha256']);
    lifecycleCheck('user-edited extracted files and identity survive lifecycle changes',
        $folder->get('Lifecycle/Extracted/kept.txt')->getId() === $fixture['outputFileId']
        && $folder->get('Lifecycle/Extracted/kept.txt')->getContent() === 'User-edited output must survive purge.');
}
function lifecycleOutput(): \OCP\Migration\IOutput {
    return new class implements \OCP\Migration\IOutput {
        public function debug(string $message): void {}
        public function info($message): void {}
        public function warning($message): void {}
        public function startProgress($max = 0): void {}
        public function advance($step = 1, $description = ''): void {}
        public function finishProgress(): void {}
    };
}
function lifecycleSetState(string $state): void {
    global $db;
    $qb = $db->getQueryBuilder();
    $qb->update('archive_tools_state')->set('state', $qb->createNamedParameter($state))
        ->where($qb->expr()->eq('id', $qb->createNamedParameter(1)))->executeStatement();
}
function lifecycleMetadataStorage(string $id): \OCP\Files\Storage\IStorage {
    return new \OCA\ArchiveTools\Service\MetadataOnlyStorage(['identity' => $id]);
}

try {
    $fixture = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR) : [];
    if ($action === 'seed') {
        lifecycleCheck('first install initializes a ready durable lifecycle marker', preg_match('/^ready:[a-f0-9]{32}$/D', (string)$lifecycle->state()) === 1);
        $folder = lifecycleUserFolder()->newFolder('Lifecycle');
        $path = tempnam('/tmp', 'lifecycle-zip-');
        try {
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('Cannot create the fixture ZIP.'); }
            $zip->addFromString('member.txt', 'Protected lifecycle fixture bytes.');
            $zip->setPassword('LifecycleArchive-Public-2026!');
            $zip->setEncryptionName('member.txt', ZipArchive::EM_AES_256);
            $zip->close();
            $source = $folder->newFile('source.zip', file_get_contents($path));
        } finally { unlink($path); }
        $output = $folder->newFolder('Extracted');
        $outputFile = $output->newFile('kept.txt', 'User-edited output must survive purge.');
        $server->get(SettingsService::class)->update(false, 15, true, 7);
        $controller = $server->get(\OCA\ArchiveTools\Controller\MountController::class);
        $response = $controller->create($source->getId(), 'Connected', 'LifecycleArchive-Public-2026!', true);
        lifecycleCheck('protected pinned connection is published through the guarded native controller', $response->getStatus() === 201);
        $mountId = $response->getData()['id'];
        $queued = $server->get(ExtractionService::class)->schedule('fixture_admin', $source->getId(), $folder->getId(), 'Queued output', [], 'LifecycleArchive-Public-2026!');
        $terminal = $jobs->find($queued['id']);
        unset($terminal['id']);
        $terminal['state'] = 'failed'; $terminal['active_key'] = null; $terminal['target_id'] = $output->getId();
        $terminal['updated_at'] = time() - 10000;
        $terminalId = $jobs->create($terminal);
        $server->get(\OCP\Config\IUserConfig::class)->setValueString('fixture_admin', Application::APP_ID, 'fixturePreference', 'retained-on-disable');
        $server->get(\OCP\IAppConfig::class)->setValueString('lifecycle_foreign_fixture', 'mustRemain', 'foreign-setting');
        $server->get(\OCA\ArchiveTools\Service\NotificationService::class)->jobFinished('fixture_admin', $terminalId, 'source.zip', 'failed', $output->getId());
        $foreignArguments = ['lifecycleFixture' => (string)getenv('ARCHIVE_TOOLS_LIFECYCLE_FIXTURE')];
        $queue->add(\OC\Log\Rotate::class, $foreignArguments);
        $orphan = lifecycleMetadataStorage('archive_tools:2147483646:' . str_repeat('a', 24))->getCache();
        $orphan->put('', ['mimetype' => 'httpd/unix-directory', 'size' => 0, 'mtime' => 1, 'etag' => 'fixture-root', 'permissions' => 1]);
        $orphanFile = $orphan->put('orphan.txt', ['mimetype' => 'text/plain', 'size' => 1, 'mtime' => 1, 'etag' => 'fixture-file', 'permissions' => 1]);
        $foreign = lifecycleMetadataStorage('archive_tools:not-an-owned-storage')->getCache();
        $foreign->put('', ['mimetype' => 'httpd/unix-directory', 'size' => 0, 'mtime' => 1, 'etag' => 'foreign', 'permissions' => 1]);
        $expiredInput = '/tmp/archive-tools-input-' . bin2hex(random_bytes(16));
        $freshInput = '/tmp/archive-tools-input-' . bin2hex(random_bytes(16));
        foreach ([$expiredInput, $freshInput] as $directory) {
            mkdir($directory, 0700);
            symlink($source->getStorage()->getLocalFile($source->getInternalPath()), $directory . '/archive.bin');
        }
        touch($expiredInput, time() - 7200);
        $expiredNames = '/tmp/archive-tools-names-' . substr(hash('sha256', getenv('ARCHIVE_TOOLS_LIFECYCLE_FIXTURE') . 'expired'), 0, 6);
        $freshNames = '/tmp/archive-tools-names-' . substr(hash('sha256', getenv('ARCHIVE_TOOLS_LIFECYCLE_FIXTURE') . 'fresh'), 0, 6);
        foreach ([$expiredNames, $freshNames] as $list) { file_put_contents($list, "member.txt\n"); chmod($list, 0600); }
        touch($expiredNames, time() - 7200);
        $fixture = ['sourceId' => $source->getId(), 'sourceSha256' => hash('sha256', $source->getContent()),
            'outputFileId' => $outputFile->getId(), 'mountId' => $mountId, 'queuedId' => $queued['id'],
            'terminalId' => $terminalId, 'terminalUpdated' => $terminal['updated_at'],
            'orphanStorageId' => $orphan->getNumericStorageId(), 'orphanFileId' => $orphanFile,
            'foreignStorageId' => $foreign->getNumericStorageId(), 'foreignArguments' => $foreignArguments,
            'expiredInput' => $expiredInput, 'freshInput' => $freshInput, 'expiredNames' => $expiredNames, 'freshNames' => $freshNames];
        file_put_contents($manifestPath, json_encode($fixture, JSON_THROW_ON_ERROR)); chmod($manifestPath, 0600);
        lifecycleCheck('dry-run report counts protected credentials without exposing their values', $cleanup->report()['protectedConnections'] === 1 && $cleanup->report()['protectedTasks'] === 2);
    } elseif ($action === 'missing') {
        $before = $lifecycle->state();
        $db->getQueryBuilder()->delete('archive_tools_state')->executeStatement();
        $called = false;
        $rejected = false;
        try { $lifecycle->write(function () use (&$called): void { $called = true; }); } catch (RuntimeException) { $rejected = true; }
        lifecycleCheck('missing durable state rejects writes without invoking their callback', $rejected && !$called);
        $provider = $server->get(\OCA\ArchiveTools\Mount\MountProvider::class);
        $user = $server->get(\OCP\IUserManager::class)->get('fixture_admin');
        lifecycleCheck('missing durable state suppresses only Archive Tools mounts', $provider->getMountsForUser($user, $server->get(\OCP\Files\Storage\IStorageFactory::class)) === []);
        lifecycleFiles($fixture);
        $lifecycle->initialize();
        lifecycleCheck('explicit repair initializes missing state after fail-closed bootstrap', str_starts_with((string)$lifecycle->state(), 'ready:'));
    } elseif ($action === 'preserved') {
        lifecycleFiles($fixture);
        lifecycleCheck('ordinary disable and re-enable preserve pinned connection and credential',
            (bool)$mounts->find($fixture['mountId'])['pinned'] && $mounts->find($fixture['mountId'])['password'] !== null);
        lifecycleCheck('ordinary disable and re-enable preserve administrator settings', $server->get(SettingsService::class)->getSettings()['idleTimeoutMinutes'] === 15);
        lifecycleCheck('ordinary disable and re-enable preserve per-user app preferences',
            $server->get(\OCP\Config\IUserConfig::class)->getValueString('fixture_admin', Application::APP_ID, 'fixturePreference', '') === 'retained-on-disable');
    } elseif ($action === 'repair') {
        $server->get(RepairApplicationState::class)->run(lifecycleOutput());
        $terminal = $jobs->find($fixture['terminalId']);
        lifecycleCheck('upgrade repair scrubs terminal credentials without changing history age', $terminal['secret'] === null && (int)$terminal['updated_at'] === $fixture['terminalUpdated']);
        lifecycleCheck('upgrade repair preserves queued credentials and pinned connection', $jobs->find($fixture['queuedId'])['secret'] !== null && (bool)$mounts->find($fixture['mountId'])['pinned']);
        lifecycleCheck('upgrade repair preserves administrator settings', $server->get(SettingsService::class)->getSettings()['idleTimeoutMinutes'] === 15);
    } elseif ($action === 'busy') {
        $jobs->update($fixture['queuedId'], ['state' => 'running']);
        $rejected = false;
        try { $cleanup->purge(); } catch (RuntimeException) { $rejected = true; }
        lifecycleCheck('durable running-job evidence rejects purge before disabling the app', $rejected && $apps->isEnabledForAnyone(Application::APP_ID));
        lifecycleCheck('a refused purge preserves the active credential and ready marker', $jobs->find($fixture['queuedId'])['secret'] !== null && str_starts_with((string)$lifecycle->state(), 'ready:'));
        $jobs->update($fixture['queuedId'], ['state' => 'queued']);
        $lease = $mounts->beginReadLease($fixture['mountId']);
        try {
            $rejected = false;
            try { $cleanup->purge(); } catch (RuntimeException) { $rejected = true; }
            lifecycleCheck('durable active-stream evidence rejects purge before disabling the app', $rejected && $apps->isEnabledForAnyone(Application::APP_ID));
        } finally { $mounts->endReadLease($lease); }
    } elseif ($action === 'cron-temporary') {
        $result = $server->get(\OCA\ArchiveTools\Service\IdleCleanupService::class)->cleanup();
        lifecycleCheck('normal cron cleanup removes expired aliases and literal-name lists', $result['inputsCleaned'] >= 1 && $result['nameListsCleaned'] >= 1);
        lifecycleCheck('normal cron cleanup retains fresh temporary resources', is_dir($fixture['freshInput']) && is_file($fixture['freshNames']));
        lifecycleCheck('normal cron cleanup preserves the pinned connection and queued credential', (bool)$mounts->find($fixture['mountId'])['pinned'] && $jobs->find($fixture['queuedId'])['secret'] !== null);
        // Restore only our expired fixtures so explicit purge verifies its own
        // recovery independently from the normal cron cleanup path.
        mkdir($fixture['expiredInput'], 0700);
        symlink(readlink($fixture['freshInput'] . '/archive.bin'), $fixture['expiredInput'] . '/archive.bin');
        touch($fixture['expiredInput'], time() - 7200);
        file_put_contents($fixture['expiredNames'], "member.txt\n"); chmod($fixture['expiredNames'], 0600); touch($fixture['expiredNames'], time() - 7200);
    } elseif ($action === 'controllers-busy') {
        lifecycleUserFolder();
        $archive = $server->get(\OCA\ArchiveTools\Controller\ArchiveController::class);
        lifecycleCheck('inspect maps the cleanup barrier to HTTP409', $archive->inspect($fixture['sourceId'], 'LifecycleArchive-Public-2026!')->getStatus() === 409);
        lifecycleCheck('download maps the cleanup barrier to HTTP409', $archive->download($fixture['sourceId'], 'member.txt', 'LifecycleArchive-Public-2026!')->getStatus() === 409);
        lifecycleCheck('extraction submission maps the cleanup barrier to HTTP409', $archive->extract($fixture['sourceId'])->getStatus() === 409);
        lifecycleCheck('connection creation maps the cleanup barrier to HTTP409', $server->get(\OCA\ArchiveTools\Controller\MountController::class)->create($fixture['sourceId'])->getStatus() === 409);
        lifecycleCheck('task polling maps the cleanup barrier to HTTP503', $server->get(\OCA\ArchiveTools\Controller\JobController::class)->index()->getStatus() === 503);
        lifecycleCheck('settings writes map the cleanup barrier to HTTP503', $server->get(\OCA\ArchiveTools\Controller\SettingsController::class)->update(false, 15, true, 7)->getStatus() === 503);
    } elseif ($action === 'hold' || $action === 'hold-exclusive') {
        $hold = static function (): void {
            file_put_contents('/tmp/archive-tools-lifecycle-holder-ready', 'ready');
            try { sleep(6); } finally { unlink('/tmp/archive-tools-lifecycle-holder-ready'); }
        };
        if ($action === 'hold-exclusive') { $lifecycle->exclusive($hold); }
        else { $lease = $lifecycle->enter(); try { $hold(); } finally { $lease->close(); } }
    } elseif ($action === 'purged') {
        lifecycleCheck('explicit purge leaves a durable nonsecret tombstone', $lifecycle->state() === 'purged');
        $report = $cleanup->report();
        lifecycleCheck('explicit purge deletes connection credentials task plans and lease rows', $report['connections'] === 0 && $report['tasks'] === 0 && $report['activeStreams'] === 0);
        lifecycleCheck('explicit purge removes only strict app-owned virtual storages', $report['virtualStorages'] === 0);
        foreach ([$fixture['orphanStorageId'], $fixture['foreignStorageId']] as $id) {
            $qb = $db->getQueryBuilder();
            $exists = $qb->select('numeric_id')->from('storages')->where($qb->expr()->eq('numeric_id', $qb->createNamedParameter($id)))->executeQuery()->fetchOne() !== false;
            lifecycleCheck($id === $fixture['orphanStorageId'] ? 'orphan app cache storage is removed' : 'unrelated similar-prefix storage is preserved', $exists === ($id === $fixture['foreignStorageId']));
        }
        lifecycleCheck('native extraction and cleanup queue registrations are removed', !$queue->has(ExtractJob::class, ['jobId' => $fixture['queuedId']]) && !$queue->has(CleanupJob::class, null));
        lifecycleCheck('unrelated native background job is preserved', $queue->has(\OC\Log\Rotate::class, $fixture['foreignArguments']));
        lifecycleCheck('purge deletes administrator settings and installed version', $server->get(\OCP\IAppConfig::class)->getValueString(Application::APP_ID, 'installed_version', '') === '' && $server->get(SettingsService::class)->getSettings()['idleTimeoutMinutes'] === 60);
        lifecycleCheck('purge deletes app-specific user preferences', $server->get(\OCP\Config\IUserConfig::class)->getValueString('fixture_admin', Application::APP_ID, 'fixturePreference', '') === '');
        lifecycleCheck('unrelated app settings are preserved', $server->get(\OCP\IAppConfig::class)->getValueString('lifecycle_foreign_fixture', 'mustRemain', '') === 'foreign-setting');
        $manager = $server->get(\OCP\Notification\IManager::class);
        lifecycleCheck('native app notifications are removed', $manager->getCount($manager->createNotification()->setApp(Application::APP_ID)) === 0);
        $rejected = false;
        try { $lifecycle->write(static fn () => null); } catch (RuntimeException) { $rejected = true; }
        lifecycleCheck('late writers fail closed after the tombstone', $rejected);
        $rejected = false;
        try { $lifecycle->initialize(); } catch (RuntimeException) { $rejected = true; }
        lifecycleCheck('ordinary upgrade repair never reopens a purged installation', $rejected && $lifecycle->state() === 'purged');
        lifecycleCheck('explicit purge is idempotent', $cleanup->purge() === ['alreadyPurged' => true]);
        lifecycleCheck('purge removes an expired owned decoder alias', !file_exists($fixture['expiredInput']));
        lifecycleCheck('purge removes an expired owned literal-name list', !file_exists($fixture['expiredNames']));
        lifecycleCheck('purge preserves and reports a fresh decoder alias', is_link($fixture['freshInput'] . '/archive.bin') && $report['temporaryResources']['retainedFreshInputs'] >= 1);
        lifecycleCheck('purge preserves and reports a fresh literal-name list', is_file($fixture['freshNames']) && $report['temporaryResources']['retainedFreshNameLists'] >= 1);
        lifecycleFiles($fixture);
    } elseif ($action === 'reinstalled') {
        lifecycleCheck('explicit core reinstall reopens the purged tombstone through install repair', str_starts_with((string)$lifecycle->state(), 'ready:'));
        lifecycleCheck('reinstall restores only the cleanup job and default settings', $queue->has(CleanupJob::class, null) && $cleanup->report()['connections'] === 0 && $cleanup->report()['tasks'] === 0);
        lifecycleFiles($fixture);
        lifecycleSetState('purging');
        foreach ([InstallApplicationState::class, RepairApplicationState::class] as $class) {
            $rejected = false;
            try { $server->get($class)->run(lifecycleOutput()); } catch (RuntimeException) { $rejected = true; }
            lifecycleCheck('install and upgrade repairs do not reopen an interrupted purging marker', $rejected && $lifecycle->state() === 'purging');
        }
        lifecycleSetState('ready:' . bin2hex(random_bytes(16)));
    } else { throw new RuntimeException('Unknown private lifecycle fixture action.'); }
    echo json_encode(['checks' => $checks, 'status' => 'passed'], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    echo json_encode(['checks' => $checks, 'status' => 'failed', 'errorClass' => $error::class,
        'message' => $error->getMessage(), 'file' => basename($error->getFile()), 'line' => $error->getLine()], JSON_THROW_ON_ERROR) . "\n";
    exit(1);
}
