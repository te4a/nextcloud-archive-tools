<?php
declare(strict_types=1);

// Whole-app reaping is deliberately restricted to the disposable SQLite fixture.
if (PHP_SAPI !== 'cli' || !preg_match('/^[a-f0-9]{32}$/D', (string)getenv('ARCHIVE_TOOLS_LIFECYCLE_FIXTURE'))) { exit(2); }
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
\OC_App::loadApps();
$server = \OC::$server;
$config = $server->get(\OCP\IConfig::class);
if ($config->getSystemValue('dbtype') !== 'sqlite3' || $config->getSystemValue('datadirectory') !== '/var/www/html/data') { exit(2); }

use OCA\ArchiveTools\AppInfo\Application;
use OCA\ArchiveTools\Controller\MountController;
use OCA\ArchiveTools\Mount\MountProvider;
use OCA\ArchiveTools\Service\LifecycleService;
use OCA\ArchiveTools\Service\MetadataOnlyStorage;
use OCA\ArchiveTools\Service\MountRepository;
use OCA\ArchiveTools\Service\VirtualStorageCleanup;

$db = $server->get(\OCP\IDBConnection::class);
$repo = $server->get(MountRepository::class);
$cleanup = $server->get(VirtualStorageCleanup::class);
$lifecycle = $server->get(LifecycleService::class);
$appConfig = $server->get(\OCP\IAppConfig::class);
$mountCache = $server->get(\OCP\Files\Config\IUserMountCache::class);
$manifestPath = '/tmp/archive-tools-virtual-storage-fixture.json';
$fixture = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR) : [];
$checks = [];
$extra = [];
function virtualCheck(string $name, bool $condition): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($name); }
    $checks[] = ['name' => $name, 'passed' => true];
}
function virtualFolder(): \OCP\Files\Folder {
    global $server;
    $user = $server->get(\OCP\IUserManager::class)->get('fixture_admin');
    if ($user === null) { throw new RuntimeException('The private storage-cleanup owner is missing.'); }
    $server->get(\OCP\IUserSession::class)->setUser($user);
    $server->get(\OCP\Files\ISetupManager::class)->setupForUser($user);
    return $server->get(\OCP\Files\IRootFolder::class)->getUserFolder('fixture_admin');
}
function virtualExisting(string $identity): ?int {
    global $db;
    $qb = $db->getQueryBuilder();
    $id = $qb->select('numeric_id')->from('storages')->where($qb->expr()->eq('id', $qb->createNamedParameter($identity)))->executeQuery()->fetchOne();
    return $id === false ? null : (int)$id;
}
function virtualRows(int $storage): int {
    global $db;
    $qb = $db->getQueryBuilder();
    return (int)$qb->select($qb->func()->count('*'))->from('filecache')->where($qb->expr()->eq('storage', $qb->createNamedParameter($storage)))->executeQuery()->fetchOne();
}
function virtualMountRows(int $storage): int {
    global $db;
    $qb = $db->getQueryBuilder();
    return (int)$qb->select($qb->func()->count('*'))->from('mounts')
        ->where($qb->expr()->eq('storage_id', $qb->createNamedParameter($storage)))->executeQuery()->fetchOne();
}
function virtualExtendedRows(array $fileIds): int {
    global $db;
    $qb = $db->getQueryBuilder();
    return (int)$qb->select($qb->func()->count('*'))->from('filecache_extended')
        ->where($qb->expr()->in('fileid', $qb->createNamedParameter($fileIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
        ->executeQuery()->fetchOne();
}
function virtualFootprint(): array {
    global $db;
    $qb = $db->getQueryBuilder();
    $ids = array_map('intval', $qb->select('numeric_id')->from('storages')
        ->where($qb->expr()->like('id', $qb->createNamedParameter($db->escapeLikeParameter('archive_tools:') . '%')))->executeQuery()->fetchFirstColumn());
    $files = 0;
    foreach ($ids as $id) { $files += virtualRows($id); }
    return ['storages' => count($ids), 'files' => $files];
}
function virtualCached(string $identity, bool $member = false): array {
    $cache = (new MetadataOnlyStorage(['identity' => $identity]))->getCache();
    $root = $cache->put('', ['mimetype' => 'httpd/unix-directory', 'size' => 0, 'mtime' => 1, 'etag' => 'fixture-root', 'permissions' => 1]);
    $file = $member ? $cache->put('orphan.txt', ['mimetype' => 'text/plain', 'size' => 1, 'mtime' => 1, 'etag' => 'fixture-file', 'permissions' => 1]) : null;
    return ['identity' => $identity, 'storage' => $cache->getNumericStorageId(), 'root' => $root, 'file' => $file];
}
function virtualConnectionIdentity(array $row): string { return 'archive_tools:' . $row['id'] . ':' . substr(hash('sha256', $row['source_etag']), 0, 24); }
function virtualLegacy(string $name, bool $leased = false): array {
    global $repo, $db, $fixture, $server, $mountCache;
    $template = $repo->find($fixture['liveId']); unset($template['id']); $template['mount_name'] = $name;
    $id = $repo->create($template);
    $row = $repo->find($id);
    $cache = virtualCached(virtualConnectionIdentity($row), true);
    $user = $server->get(\OCP\IUserManager::class)->get('fixture_admin');
    $native = (new MetadataOnlyStorage(['identity' => $cache['identity']]))->getCache();
    $mountCache->addMount($user, '/fixture_admin/files/Storage cleanup/' . $name . '/', $native->get(''), MountProvider::class, $id);
    $lease = $leased ? $repo->beginReadLease($id) : null;
    // A legacy interrupted uninstall could leave precisely this orphan shape.
    $qb = $db->getQueryBuilder(); $qb->delete('archive_tools_mounts')->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeStatement();
    return $cache + ['connection' => $id, 'lease' => $lease];
}
function virtualExpireLease(array $lease): void {
    global $db;
    $qb = $db->getQueryBuilder();
    $qb->update('archive_tools_leases')->set('expires_at', $qb->createNamedParameter(time() - 1))
        ->where($qb->expr()->eq('token', $qb->createNamedParameter($lease['token'])))->executeStatement();
}
function virtualFiles(array $fixture): void {
    $folder = virtualFolder();
    $source = $folder->get('Storage cleanup/source.zip');
    $output = $folder->get('Storage cleanup/Extracted/kept.txt');
    virtualCheck('virtual metadata cleanup preserves source archive bytes and file id', $source->getId() === $fixture['sourceId'] && hash('sha256', $source->getContent()) === $fixture['sourceSha256']);
    virtualCheck('virtual metadata cleanup preserves user output bytes and file id', $output->getId() === $fixture['outputId'] && $output->getContent() === 'User output survives virtual cleanup.');
}
function virtualReap(): array {
    global $lifecycle, $cleanup, $appConfig;
    $result = $lifecycle->write(fn (): array => $cleanup->reapOrphans());
    virtualCheck('one orphan pass respects scan and deletion budgets', $result['scanned'] <= 1000 && $result['removed'] <= 20);
    virtualCheck('the reaper cursor is persisted through the public app-config API', $appConfig->getValueInt(Application::APP_ID, VirtualStorageCleanup::CURSOR_KEY, 0) === $result['cursor']);
    return $result;
}
try {
    $action = $argv[1] ?? '';
    if ($action === 'seed') {
        $folder = virtualFolder()->newFolder('Storage cleanup');
        $path = tempnam('/tmp', 'virtual-cache-source-');
        try {
            $zip = new ZipArchive(); $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFromString('nested/member.txt', 'Virtual cache fixture bytes.'); $zip->close();
            $source = $folder->newFile('source.zip', file_get_contents($path));
        } finally { unlink($path); }
        $output = $folder->newFolder('Extracted')->newFile('kept.txt', 'User output survives virtual cleanup.');
        $baseline = virtualFootprint();
        virtualCheck('the disposable baseline has no Archive Tools cache storages', $baseline === ['storages' => 0, 'files' => 0]);
        $fixture = ['sourceId' => $source->getId(), 'sourceSha256' => hash('sha256', $source->getContent()),
            'outputId' => $output->getId(), 'baseline' => $baseline];
    } elseif ($action === 'connect') {
        virtualFolder();
        $response = $server->get(MountController::class)->create($fixture['sourceId'], 'Connected', null, true);
        virtualCheck('real connect succeeds in a fresh request context', $response->getStatus() === 201);
        $id = $response->getData()['id']; $identity = virtualConnectionIdentity($repo->find($id));
        $numeric = virtualExisting($identity);
        virtualCheck('real connect creates a populated native virtual cache', $numeric !== null && virtualRows($numeric) >= 3);
        $fixture['current'] = ['id' => $id, 'identity' => $identity, 'storage' => $numeric];
    } elseif ($action === 'disconnect') {
        $folder = virtualFolder();
        virtualCheck('the normal connected archive returns real member bytes before deletion', $folder->get('Storage cleanup/Connected/nested/member.txt')->getContent() === 'Virtual cache fixture bytes.');
        virtualCheck('real disconnect succeeds in a fresh request context', $server->get(MountController::class)->destroy($fixture['current']['id'])->getStatus() === 200);
        virtualCheck('disconnect removes its exact storage mapping and cache entries', virtualExisting($fixture['current']['identity']) === null && virtualRows($fixture['current']['storage']) === 0);
        virtualCheck('disconnect removes all cached user mount projections for its storage', virtualMountRows($fixture['current']['storage']) === 0);
        virtualCheck('repeated connect and disconnect do not grow virtual cache footprint', virtualFootprint() === $fixture['baseline']);
    } elseif ($action === 'live') {
        virtualFolder();
        $response = $server->get(MountController::class)->create($fixture['sourceId'], 'Live', null, true);
        virtualCheck('a separate live connection remains for orphan-safety checks', $response->getStatus() === 201);
        $liveId = $response->getData()['id'];
        $fixture['liveId'] = $liveId;
        $fixture['liveIdentity'] = virtualConnectionIdentity($repo->find($liveId));
        $fixture['liveLease'] = $repo->beginReadLease($liveId);
    } elseif ($action === 'active-connection') {
        $before = virtualFootprint();
        $rejected = false;
        try { $repo->delete($fixture['liveId']); } catch (RuntimeException|\OCP\Lock\LockedException) { $rejected = true; }
        virtualCheck('a persisted active lease rejects disconnect after its original process ended', $rejected && $repo->find($fixture['liveId']) !== null);
        virtualCheck('lease refusal leaves the full cache footprint unchanged', virtualFootprint() === $before);
        virtualExpireLease($fixture['liveLease']);
    } elseif ($action === 'seed-orphans') {
        $token = (string)getenv('ARCHIVE_TOOLS_LIFECYCLE_FIXTURE');
        $fixture['malformed'] = [];
        foreach (['archive_tools:0' . $fixture['liveId'] . ':' . str_repeat('b', 24),
                  'archive_tools:abc:' . str_repeat('c', 24), 'archive_tools:9223372036854775808:' . str_repeat('d', 24),
                  'archive_tools:' . $fixture['liveId'] . ':' . str_repeat('a', 23)] as $identity) {
            $fixture['malformed'][] = virtualCached($identity, true);
        }
        $fixture['liveVersions'] = [];
        for ($i = 0; $i < 1000; ++$i) {
            $fixture['liveVersions'][] = virtualCached('archive_tools:' . $fixture['liveId'] . ':' . substr(hash('sha256', $token . ':' . $i), 0, 24));
        }
        $fixture['leasedOrphan'] = virtualLegacy('Leased legacy cache', true);
        $fixture['orphans'] = [];
        for ($i = 0; $i < 40; ++$i) { $fixture['orphans'][] = virtualLegacy('Legacy cache ' . $i); }
        $appConfig->setValueInt(Application::APP_ID, VirtualStorageCleanup::CURSOR_KEY, 0);
        virtualCheck('fixture places deletable orphans behind more than one full live prefix page', count($fixture['liveVersions']) === 1000 && virtualExisting($fixture['orphans'][0]['identity']) !== null);
    } elseif ($action === 'first-page') {
        $result = virtualReap();
        virtualCheck('the first full page scans only retained live or malformed candidates', $result['scanned'] === 1000 && $result['removed'] === 0 && $result['scanLimitReached']);
        virtualCheck('a full retained page advances its durable cursor', $result['cursor'] > 0);
        virtualCheck('late orphans are still present after the first bounded scan', virtualExisting($fixture['orphans'][0]['identity']) !== null);
        $fixture['firstCursor'] = $result['cursor'];
        $extra['pass'] = $result;
    } elseif ($action === 'second-page') {
        $result = virtualReap();
        virtualCheck('a fresh PHP process resumes after the durable cursor rather than starving late orphans', $result['cursor'] > $fixture['firstCursor'] && $result['removed'] === 20 && $result['deletionLimitReached']);
        $remaining = array_filter($fixture['orphans'], fn (array $row): bool => virtualExisting($row['identity']) !== null);
        virtualCheck('one pass removes exactly twenty eligible orphan storages', count($remaining) === 20);
        virtualCheck('orphan metadata with an active durable lease is preserved', virtualExisting($fixture['leasedOrphan']['identity']) !== null);
        virtualCheck('the live connection and its current cache remain', $repo->find($fixture['liveId']) !== null && virtualExisting($fixture['liveIdentity']) !== null);
        $fixture['secondCursor'] = $result['cursor']; $extra['pass'] = $result;
    } elseif ($action === 'third-page') {
        $result = virtualReap();
        virtualCheck('the next batch resumes after the deletion limit and drains the other twenty orphans', $result['removed'] === 20 && $result['cursor'] > $fixture['secondCursor']);
        foreach ($fixture['orphans'] as $row) {
            virtualCheck('orphan removal clears its cache and storage identity', virtualExisting($row['identity']) === null && virtualRows($row['storage']) === 0);
            virtualCheck('orphan removal clears cached user mount projections', virtualMountRows($row['storage']) === 0);
        }
        foreach ($fixture['malformed'] as $row) { virtualCheck('malformed or out-of-range identities remain untouched', virtualExisting($row['identity']) === $row['storage'] && virtualRows($row['storage']) >= 2); }
        $extra['pass'] = $result;
    } elseif ($action === 'lease-expired') {
        virtualExpireLease($fixture['leasedOrphan']['lease']);
        $wrapped = false;
        for ($i = 0; $i < 4 && virtualExisting($fixture['leasedOrphan']['identity']) !== null; ++$i) {
            $result = virtualReap(); $wrapped = $wrapped || $result['wrapped'];
        }
        virtualCheck('a completed cursor cycle wraps without starving an earlier newly eligible orphan', $wrapped && virtualExisting($fixture['leasedOrphan']['identity']) === null);
        virtualCheck('reaping an expired orphan still preserves every live version', count(array_filter($fixture['liveVersions'], fn (array $row): bool => virtualExisting($row['identity']) !== null)) === 1000);
    } elseif ($action === 'rootless') {
        $identity = 'archive_tools:999999999:' . substr(hash('sha256', getenv('ARCHIVE_TOOLS_LIFECYCLE_FIXTURE')), 0, 24);
        $cache = (new MetadataOnlyStorage(['identity' => $identity]))->getCache();
        $numeric = $cache->getNumericStorageId();
        $fileIds = [];
        for ($i = 0; $i < 201; ++$i) {
            $fileIds[] = $cache->put('rootless-' . $i . '.txt', [
                'mimetype' => 'text/plain', 'size' => 1, 'mtime' => 1, 'creation_time' => 1, 'permissions' => 1, 'parent' => 999999999,
            ]);
        }
        virtualCheck('the legacy orphan has more than one batch of entries and no cache root or mount',
            $cache->get('') === false && virtualMountRows($numeric) === 0 && virtualRows($numeric) === 201);
        virtualCheck('the rootless fixtures include extended metadata', virtualExtendedRows($fileIds) === 201);
        $appConfig->setValueInt(Application::APP_ID, VirtualStorageCleanup::CURSOR_KEY, $numeric - 1);
        virtualCheck('the orphan reaper removes a storage with no root or cached user mount', virtualReap()['removed'] === 1);
        virtualCheck('the bounded cache search removes every batch before removing the registry entry',
            virtualRows($numeric) === 0 && virtualExisting($identity) === null);
        virtualCheck('native cache removal also deletes extended metadata for rootless entries', virtualExtendedRows($fileIds) === 0);
        $replacement = (new MetadataOnlyStorage(['identity' => $identity]))->getCache()->getNumericStorageId();
        virtualCheck('the same PHP process allocates a fresh storage ID after cleanup', $replacement !== $numeric && virtualExisting($identity) === $replacement);
        virtualCheck('the re-created storage is reaped on a later pass', virtualReap()['removed'] === 1 && virtualExisting($identity) === null);
    } elseif ($action === 'remove-live') {
        $repo->delete($fixture['liveId']);
        virtualCheck('normal repository disconnect removes a connection with more than one cache page', $repo->find($fixture['liveId']) === null && virtualExisting($fixture['liveIdentity']) === null);
        virtualCheck('normal disconnect clears every cached source version beyond the first thousand', count(array_filter($fixture['liveVersions'], fn (array $row): bool => virtualExisting($row['identity']) !== null)) === 0);
        foreach ($fixture['malformed'] as $row) { virtualCheck('connection cleanup never widens to malformed matching prefixes', virtualExisting($row['identity']) === $row['storage']); }
        virtualFiles($fixture);
    } elseif ($action === 'remove-all') {
        $all = [];
        for ($i = 0; $i < 1001; ++$i) { $all[] = virtualCached('archive_tools:' . (1000000000 + $i) . ':' . substr(hash('sha256', getenv('ARCHIVE_TOOLS_LIFECYCLE_FIXTURE') . ':all:' . $i), 0, 24)); }
        virtualCheck('whole-app cache removal fixture exceeds a complete scan page', $cleanup->countAll() === 1001);
        $removed = $lifecycle->exclusive(fn (): int => $cleanup->removeAll());
        virtualCheck('explicit whole-app cache removal drains every strict identity across pages', $removed === 1001 && $cleanup->countAll() === 0);
        foreach ($fixture['malformed'] as $row) { virtualCheck('whole-app cache removal preserves malformed namespace lookalikes', virtualExisting($row['identity']) === $row['storage']); }
        virtualCheck('repeated whole-app cache cleanup is idempotent', $lifecycle->exclusive(fn (): int => $cleanup->removeAll()) === 0);
        virtualFiles($fixture);
    } else { throw new RuntimeException('Unknown private virtual-cache fixture action.'); }
    file_put_contents($manifestPath, json_encode($fixture, JSON_THROW_ON_ERROR)); chmod($manifestPath, 0600);
    echo json_encode(['status' => 'passed', 'checks' => $checks] + $extra, JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    echo json_encode(['status' => 'failed', 'checks' => $checks, 'errorClass' => $error::class,
        'message' => $error->getMessage(), 'file' => basename($error->getFile()), 'line' => $error->getLine()], JSON_THROW_ON_ERROR) . "\n"; exit(1);
}
