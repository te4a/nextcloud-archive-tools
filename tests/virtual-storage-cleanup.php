<?php
declare(strict_types=1);

// Whole-app reaping is deliberately restricted to this disposable SQLite fixture.
if (PHP_SAPI !== 'cli' || !preg_match('/^[a-f0-9]{32}$/D', (string)getenv('ARCHIVE_TOOLS_LIFECYCLE_FIXTURE'))) { exit(2); }
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
\OC_App::loadApps();

use OCA\ArchiveTools\AppInfo\Application;
use OCA\ArchiveTools\Controller\MountController;
use OCA\ArchiveTools\Mount\MountProvider;
use OCA\ArchiveTools\Repair\RepairApplicationState;
use OCA\ArchiveTools\Service\LifecycleService;
use OCA\ArchiveTools\Service\MetadataOnlyStorage;
use OCA\ArchiveTools\Service\MountRepository;
use OCA\ArchiveTools\Service\VirtualStorageCleanup;
use OCA\ArchiveTools\Storage\AccessCache;
use OCA\ArchiveTools\Storage\ArchiveStorage;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Cache\ICache;
use OCP\Files\Cache\ICacheEntry;
use OCP\Files\Folder;
use OCP\Migration\IOutput;

$server = \OC::$server;
$config = $server->get(\OCP\IConfig::class);
if ($config->getSystemValue('dbtype') !== 'sqlite3' || $config->getSystemValue('datadirectory') !== '/var/www/html/data') { exit(2); }

$db = $server->get(\OCP\IDBConnection::class);
$repo = $server->get(MountRepository::class);
$cleanup = $server->get(VirtualStorageCleanup::class);
$lifecycle = $server->get(LifecycleService::class);
$appConfig = $server->get(\OCP\IAppConfig::class);
$mountCache = $server->get(\OCP\Files\Config\IUserMountCache::class);
$manifest = '/tmp/archive-tools-virtual-storage-fixture.json';
$fixture = is_file($manifest) ? json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR) : [];
$checks = [];
$extra = [];

function virtualCheck(string $name, bool $condition): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($name); }
    $checks[] = ['name' => $name, 'passed' => true];
}

function virtualFolder(): Folder {
    global $server;
    $user = $server->get(\OCP\IUserManager::class)->get('fixture_admin');
    if ($user === null) { throw new RuntimeException('The private storage-cleanup owner is missing.'); }
    $server->get(\OCP\IUserSession::class)->setUser($user);
    $server->get(\OCP\Files\ISetupManager::class)->setupForUser($user);
    return $server->get(\OCP\Files\IRootFolder::class)->getUserFolder('fixture_admin');
}

function virtualRegistryId(string $identity): ?int {
    global $db;
    $qb = $db->getQueryBuilder();
    $id = $qb->select('numeric_id')->from('storages')
        ->where($qb->expr()->eq('id', $qb->createNamedParameter($identity)))->executeQuery()->fetchOne();
    return $id === false ? null : (int)$id;
}

function virtualStorageRows(int $storage): int {
    global $db;
    $qb = $db->getQueryBuilder();
    return (int)$qb->select($qb->func()->count('*'))->from('filecache')
        ->where($qb->expr()->eq('storage', $qb->createNamedParameter($storage)))->executeQuery()->fetchOne();
}

function virtualMountRows(int $root): int {
    global $db;
    $qb = $db->getQueryBuilder();
    return (int)$qb->select($qb->func()->count('*'))->from('mounts')
        ->where($qb->expr()->eq('root_id', $qb->createNamedParameter($root)))->executeQuery()->fetchOne();
}

function virtualExtendedRows(array $fileIds): int {
    global $db;
    $qb = $db->getQueryBuilder();
    return (int)$qb->select($qb->func()->count('*'))->from('filecache_extended')
        ->where($qb->expr()->in('fileid', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)))
        ->executeQuery()->fetchOne();
}

function virtualShared(): ICache {
    return (new MetadataOnlyStorage())->getCache();
}

function virtualDir(ICache $cache, string $path): int {
    return $cache->put($path, ['mimetype' => 'httpd/unix-directory', 'size' => 0, 'mtime' => 1, 'etag' => 'fixture-dir', 'permissions' => 1]);
}

function virtualMember(ICache $cache, string $path, ?int $parent = null): int {
    $data = ['mimetype' => 'text/plain', 'size' => 1, 'mtime' => 1, 'creation_time' => 1, 'etag' => 'fixture-member', 'permissions' => 1];
    if ($parent !== null) {
        $data['parent'] = $parent;
    }
    return $cache->put($path, $data);
}

function virtualProjection(ICacheEntry $root, string $name, int $connection): void {
    global $server, $mountCache;
    $user = $server->get(\OCP\IUserManager::class)->get('fixture_admin');
    $mountCache->addMount($user, '/fixture_admin/files/Storage cleanup/' . $name . '/', $root, MountProvider::class, $connection);
}

function virtualBranch(int $id, bool $project = false, bool $reserve = true): array {
    global $repo;
    $cache = virtualShared();
    $branch = ArchiveStorage::branch($id);
    if ($reserve) {
        $repo->reserveCache($id, ArchiveStorage::STORAGE_ID, $branch);
    }
    $root = virtualDir($cache, $branch);
    $member = virtualMember($cache, $branch . '/orphan.txt');
    if ($project) {
        virtualProjection($cache->get($branch), $branch, $id);
    }
    return ['path' => $branch, 'root' => $root, 'member' => $member];
}

function virtualConnect(string $name, array $fixture): array {
    global $server, $repo;
    virtualFolder();
    $response = $server->get(MountController::class)->create($fixture['sourceId'], $name, null, true);
    virtualCheck('real connection is created in the request context', $response->getStatus() === 201);
    $id = (int)$response->getData()['id'];
    $branch = ArchiveStorage::branch($id);
    virtualCheck('real connect reserves its exact branch in the app ledger', $repo->find($id)['cache_path'] === $branch);
    $cache = virtualShared();
    $root = $cache->get($branch);
    $member = $cache->get($branch . '/nested/member.txt');
    virtualCheck('real connection has a canonical root and member in the shared native cache', $root instanceof ICacheEntry && $member instanceof ICacheEntry);
    return ['id' => $id, 'path' => $branch, 'root' => $root->getId(), 'member' => $member->getId(), 'name' => $name];
}

function virtualFiles(array $fixture): void {
    $folder = virtualFolder();
    $source = $folder->get('Storage cleanup/source.zip');
    $output = $folder->get('Storage cleanup/Extracted/kept.txt');
    virtualCheck('metadata cleanup preserves source archive bytes and file id', $source->getId() === $fixture['sourceId'] && hash('sha256', $source->getContent()) === $fixture['sourceSha256']);
    virtualCheck('metadata cleanup preserves user output bytes and file id', $output->getId() === $fixture['outputId'] && $output->getContent() === 'User output survives virtual cleanup.');
}

function virtualReap(): array {
    global $lifecycle, $cleanup, $appConfig;
    $result = $lifecycle->write(fn (): array => $cleanup->reapOrphans());
    virtualCheck('one pass respects scan and deletion budgets', $result['scanned'] <= VirtualStorageCleanup::SCAN_LIMIT && $result['removed'] <= VirtualStorageCleanup::DELETE_LIMIT);
    virtualCheck('orphan scan progress persists across requests', $appConfig->getValueInt(Application::APP_ID, VirtualStorageCleanup::CURSOR_KEY, 0) === $result['cursor']);
    return $result;
}

function virtualCopyConnection(int $fromId, string $name): int {
    global $repo;
    $row = $repo->find($fromId);
    unset($row['id'], $row['cache_identity'], $row['cache_path'], $row['cache_numeric_id']);
    $row['mount_name'] = $name;
    return $repo->create($row);
}

function virtualForgetRoute(int $id): void {
    global $db;
    $qb = $db->getQueryBuilder();
    $qb->delete('archive_tools_cache')->where($qb->expr()->eq('mount_id', $qb->createNamedParameter($id)))->executeStatement();
}

function virtualRepair(): void {
    global $server;
    $server->get(RepairApplicationState::class)->run(new class implements IOutput {
        public function debug(string $message): void {}
        public function info($message): void {}
        public function warning($message): void {}
        public function startProgress($max = 0): void {}
        public function advance($step = 1, $description = ''): void {}
        public function finishProgress(): void {}
    });
}

try {
    $action = $argv[1] ?? '';
    if ($action === 'seed') {
        $folder = virtualFolder()->newFolder('Storage cleanup');
        $path = tempnam('/tmp', 'virtual-cache-source-');
        try {
            $zip = new ZipArchive();
            $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFromString('nested/member.txt', 'Virtual cache fixture bytes.');
            $zip->close();
            $source = $folder->newFile('source.zip', file_get_contents($path));
        } finally {
            unlink($path);
        }
        $output = $folder->newFolder('Extracted')->newFile('kept.txt', 'User output survives virtual cleanup.');
        virtualCheck('disposable baseline has no archive cache branches', $cleanup->countAll() === 0);
        $fixture = ['sourceId' => $source->getId(), 'sourceSha256' => hash('sha256', $source->getContent()), 'outputId' => $output->getId()];
    } elseif ($action === 'connect') {
        $fixture['first'] = virtualConnect('First', $fixture);
        $fixture['live'] = virtualConnect('Second', $fixture);
        $fixture['storage'] = virtualRegistryId(ArchiveStorage::STORAGE_ID);
        virtualCheck('two real archive connections share one registry identity with different file IDs',
            $fixture['storage'] !== null && $fixture['first']['root'] !== $fixture['live']['root'] && $fixture['first']['member'] !== $fixture['live']['member']);
        virtualCheck('both canonical roots have a cached user mount projection', virtualMountRows($fixture['first']['root']) === 1 && virtualMountRows($fixture['live']['root']) === 1);
    } elseif ($action === 'disconnect') {
        $folder = virtualFolder();
        $first = $fixture['first'];
        $live = $fixture['live'];
        foreach ([$first, $live] as $connection) {
            $path = 'Storage cleanup/' . $connection['name'] . '/nested/member.txt';
            virtualCheck('real mounted member opens from the native Files path in a fresh request',
                $folder->get($path)->getContent() === 'Virtual cache fixture bytes.');
        }
        $searchIds = array_map(static fn (\OCP\Files\Node $node): int => $node->getId(), $folder->search('member.txt'));
        sort($searchIds);
        $expectedIds = [$first['member'], $live['member']];
        sort($expectedIds);
        virtualCheck('native Files search returns the two independently mounted members',
            $searchIds === $expectedIds);
        $native = virtualShared();
        $scoped = new AccessCache($native, $first['path'], static fn (): bool => true,
            static fn (callable $read): array => $read(), static fn (ICache $cache): int => $cache->getId($first['path']));
        virtualCheck('scoped cache hides sibling branches for entry and path lookups by file ID',
            $scoped->get($live['member']) === false && $scoped->getPathById($live['member']) === null
            && $scoped->getFolderContentsById($live['root']) === []);
        virtualCheck('scoped cache searches yield mount-relative files from only the selected connection',
            array_map(static fn (ICacheEntry $entry): string => $entry->getPath(), $scoped->search('member%')) === ['nested/member.txt']
            && array_map(static fn (ICacheEntry $entry): int => $entry->getId(), $scoped->searchByMime('text')) === [$first['member']]
            && array_map(static fn (ICacheEntry $entry): int => $entry->getId(), $scoped->searchByMime('text/plain')) === [$first['member']]);
        virtualCheck('scoped scanning retains the root size and member size',
            $scoped->get('')->getSize() === strlen('Virtual cache fixture bytes.')
            && $scoped->get('nested/member.txt')->getSize() === strlen('Virtual cache fixture bytes.'));
        virtualCheck('ordinary disconnect succeeds', $server->get(MountController::class)->destroy($first['id'])->getStatus() === 200);
        $cache = virtualShared();
        virtualCheck('disconnect removes only the selected archive branch and projections',
            $cache->get($first['path']) === false && $cache->get($first['member']) === false && virtualMountRows($first['root']) === 0);
        virtualCheck('second archive retains its canonical root, member, and projection',
            $cache->getId($live['path']) === $live['root'] && $cache->get($live['member']) !== false && virtualMountRows($live['root']) === 1);
        virtualCheck('ordinary disconnect keeps the shared native registry identity', virtualRegistryId(ArchiveStorage::STORAGE_ID) === $fixture['storage']);
        virtualFiles($fixture);
    } elseif ($action === 'legacy') {
        $live = $fixture['live']['id'];
        foreach ([['beta', 'Historical projected', true], ['unprojectedBeta', 'Historical without projection', false]] as [$key, $name, $project]) {
            $id = virtualCopyConnection($live, $name);
            virtualForgetRoute($id);
            $identity = MetadataOnlyStorage::legacyIdentity($id, $repo->find($id)['source_etag']);
            $legacy = (new MetadataOnlyStorage(['identity' => $identity]))->getCache();
            $numeric = $legacy->getNumericStorageId();
            $root = virtualDir($legacy, '');
            virtualDir($legacy, 'nested');
            $member = virtualMember($legacy, 'nested/member.txt');
            if ($project) {
                virtualProjection($legacy->get(''), $name, $id);
            }
            virtualCheck('legacy fixture has original cache IDs before repair',
                $numeric !== $fixture['storage'] && virtualMountRows($root) === ($project ? 1 : 0) && virtualExtendedRows([$member]) === 1);
            $fixture[$key] = compact('id', 'name', 'identity', 'numeric', 'root', 'member');
        }
        $priorId = virtualCopyConnection($live, 'Previous shared layout');
        virtualForgetRoute($priorId);
        $priorPath = ArchiveStorage::previousBranch($priorId, $repo->find($priorId)['source_etag']);
        $prior = virtualDir(virtualShared(), $priorPath);
        $fixture['prior'] = ['id' => $priorId, 'path' => $priorPath, 'root' => $prior];
        $unknown = MetadataOnlyStorage::legacyIdentity($live + 1000000, 'unknown-old-cache');
        $unknownCache = (new MetadataOnlyStorage(['identity' => $unknown]))->getCache();
        virtualDir($unknownCache, '');
        $fixture['unknownBetaId'] = $unknownCache->getNumericStorageId();
        virtualRepair();
        virtualRepair();

        $projected = $repo->find($fixture['beta']['id']);
        $unprojected = $repo->find($fixture['unprojectedBeta']['id']);
        virtualCheck('repair adopts existing legacy routes without moving their metadata',
            $projected['cache_identity'] === $fixture['beta']['identity'] && $projected['cache_path'] === ''
            && (int)$projected['cache_numeric_id'] === $fixture['beta']['numeric']
            && virtualMountRows($fixture['beta']['root']) === 1 && virtualExtendedRows([$fixture['beta']['member']]) === 1);
        virtualCheck('repair records legacy route even when no projection currently exists',
            $unprojected['cache_identity'] === $fixture['unprojectedBeta']['identity'] && $unprojected['cache_path'] === ''
            && $unprojected['cache_numeric_id'] === null);
        $previous = $repo->find($priorId);
        virtualCheck('repair retains the old shared branch and its native file ID',
            $previous['cache_identity'] === ArchiveStorage::STORAGE_ID && $previous['cache_path'] === $priorPath
            && virtualShared()->getId($priorPath) === $prior);
    } elseif ($action === 'legacy-read') {
        $folder = virtualFolder();
        foreach (['beta', 'unprojectedBeta'] as $key) {
            $case = $fixture[$key];
            $path = 'Storage cleanup/' . $case['name'];
            virtualCheck('mounted legacy root keeps the old file ID after repair', $folder->get($path)->getId() === $case['root']);
            $file = $folder->get($path . '/nested/member.txt');
            virtualCheck('legacy member keeps its file ID and opens normally',
                $file->getId() === $case['member'] && $file->getContent() === 'Virtual cache fixture bytes.');
            virtualCheck('first access records a legacy numeric storage without reallocation',
                (int)$repo->find($case['id'])['cache_numeric_id'] === $case['numeric']);
            $legacy = (new MetadataOnlyStorage(['identity' => $case['identity']]))->getCache();
            $readable = false;
            $scoped = new AccessCache($legacy, '', static function () use (&$readable): bool { return $readable; },
                static fn (callable $read): array => $read(), static fn (ICache $cache): int => $cache->getId(''));
            virtualCheck('denied legacy cache only exposes identities needed to assemble the mount',
                $scoped->getId('') === $case['root'] && $scoped->getNumericStorageId() === $case['numeric']
                && $scoped->get('') === false && $scoped->get($case['member']) === false
                && $scoped->getId('nested/member.txt') === -1 && $scoped->getPathById($case['member']) === null
                && $scoped->getFolderContents('') === [] && $scoped->search('member%') === []);
            $scoped->remove('');
            $readable = true;
            virtualCheck('the same legacy cache resumes native metadata when access becomes available',
                $scoped->get('')->getId() === $case['root'] && $scoped->get($case['member'])->getPath() === 'nested/member.txt');
            virtualCheck('explicit legacy disconnect clears that metadata and exact projections',
                $server->get(MountController::class)->destroy($case['id'])->getStatus() === 200
                && virtualStorageRows($case['numeric']) === 0 && virtualMountRows($case['root']) === 0
                && virtualRegistryId($case['identity']) === $case['numeric']);
        }
        $fixture['betaId'] = $fixture['beta']['numeric'];
        virtualCheck('untracked old cache and separate shared branches are untouched',
            virtualStorageRows($fixture['unknownBetaId']) === 1
            && virtualShared()->getId($fixture['live']['path']) === $fixture['live']['root']
            && virtualShared()->getId($fixture['prior']['path']) === $fixture['prior']['root']);
        $lifecycle->write(fn () => $repo->delete($fixture['prior']['id']));
    } elseif ($action === 'seed-orphans') {
        $cache = virtualShared();
        $live = $fixture['live'];
        for ($i = 0; $i < 1200; ++$i) {
            virtualMember($cache, $live['path'] . '/live-extra-' . $i);
        }
        $leaseOwner = virtualCopyConnection($live['id'], 'Leased orphan');
        $fixture['lease'] = $repo->beginReadLease($leaseOwner);
        $fixture['leased'] = virtualBranch($leaseOwner, reserve: false);
        $qb = $db->getQueryBuilder();
        $qb->delete('archive_tools_mounts')->where($qb->expr()->eq('id', $qb->createNamedParameter($leaseOwner)))->executeStatement();

        $fixture['orphans'] = [];
        for ($i = 0; $i < 40; ++$i) {
            $fixture['orphans'][] = virtualBranch(1000000000 + $i, project: true);
        }
        $fixture['untracked'] = ArchiveStorage::branch(1999999998);
        virtualDir($cache, $fixture['untracked']);
        $rootlessId = 1999999999;
        $fixture['rootless'] = ArchiveStorage::branch($rootlessId);
        $repo->reserveCache($rootlessId, ArchiveStorage::STORAGE_ID, $fixture['rootless']);
        $fixture['rootlessIds'] = [];
        for ($i = 0; $i < 201; ++$i) {
            $fixture['rootlessIds'][] = virtualMember($cache, $fixture['rootless'] . '/member-' . $i, 1999999999);
        }
        virtualCheck('rootless fixture has 201 extended rows and no cache root', $cache->get($fixture['rootless']) === false && virtualExtendedRows($fixture['rootlessIds']) === 201);
        virtualCheck('admin report counts tracked cache locations and ignores the untracked root', $cleanup->countAll() === 43);
        $appConfig->setValueInt(Application::APP_ID, VirtualStorageCleanup::CURSOR_KEY, 0);
    } elseif ($action === 'first-page') {
        $result = virtualReap();
        $cache = virtualShared();
        virtualCheck('first pass sees orphan ledger entries immediately despite 1,200 earlier live files',
            $result['scanned'] === 20 && $result['removed'] === 20 && $result['deletionLimitReached']);
        $remaining = array_filter($fixture['orphans'], fn (array $branch): bool => $cache->get($branch['path']) !== false);
        virtualCheck('the deletion cap leaves twenty orphans and excludes the active lease',
            count($remaining) === 20 && $cache->get($fixture['leased']['path']) !== false);
        $fixture['firstCursor'] = $result['cursor'];
        $extra['pass'] = $result;
    } elseif ($action === 'second-page') {
        $result = virtualReap();
        $cache = virtualShared();
        virtualCheck('separate worker resumes the durable cursor and drains twenty more orphans',
            $result['scanned'] === 20 && $result['removed'] === 20 && $result['cursor'] > $fixture['firstCursor']);
        foreach ($fixture['orphans'] as $branch) {
            virtualCheck('orphan branch cache and cached user projection are removed',
                $cache->get($branch['path']) === false && $cache->get($branch['member']) === false && virtualMountRows($branch['root']) === 0);
        }
        $extra['pass'] = $result;
    } elseif ($action === 'rootless') {
        $cache = virtualShared();
        $result = virtualReap();
        virtualCheck('reaper finds a tracked location even though its cache root is missing', $result['removed'] === 1);
        virtualCheck('native cache removes all batches of rootless files and extended metadata',
            $cache->get(end($fixture['rootlessIds'])) === false && virtualExtendedRows($fixture['rootlessIds']) === 0);
        virtualCheck('explicit tracking preserves an unrelated namespace lookalike', $cache->get($fixture['untracked']) !== false);
    } elseif ($action === 'lease-expired') {
        $qb = $db->getQueryBuilder();
        $qb->update('archive_tools_leases')->set('expires_at', $qb->createNamedParameter(time() - 1))
            ->where($qb->expr()->eq('token', $qb->createNamedParameter($fixture['lease']['token'])))->executeStatement();
        $result = virtualReap();
        $cache = virtualShared();
        virtualCheck('wrapped scan revisits and removes the previously leased orphan',
            $result['wrapped'] && $result['removed'] === 1 && $cache->get($fixture['leased']['path']) === false);
        virtualCheck('reaping leaves the live connection and all its files intact',
            count($cache->getFolderContents($fixture['live']['path'])) === 1201 && $cleanup->countAll() === 1);
    } elseif ($action === 'remove-live') {
        $id = $fixture['live']['id'];
        $lifecycle->write(fn () => $repo->delete($id));
        $cache = virtualShared();
        virtualCheck('repository disconnect removes its ledger row and tracked cache exactly',
            $repo->find($id) === null && $cleanup->countAll() === 0
            && $cache->get($fixture['live']['path']) === false && $cache->get($fixture['untracked']) !== false);
        virtualFiles($fixture);
    } elseif ($action === 'remove-all') {
        for ($i = 0; $i < 1001; ++$i) {
            $id = 2000000000 + $i;
            $repo->reserveCache($id, ArchiveStorage::STORAGE_ID, ArchiveStorage::branch($id));
        }
        $removed = $lifecycle->exclusive(fn (): int => $cleanup->removeAll());
        virtualCheck('whole-app cleanup drains tracked locations across multiple pages', $removed === 1001 && $cleanup->countAll() === 0);
        virtualCheck('whole-app cleanup keeps the synthetic root for an untracked branch and the exact shared registry identity',
            virtualShared()->get('') instanceof ICacheEntry && virtualRegistryId(ArchiveStorage::STORAGE_ID) === $fixture['storage']);
        virtualCheck('whole-app cleanup does not widen to untracked shared or legacy metadata',
            virtualShared()->get($fixture['untracked']) !== false && virtualStorageRows($fixture['betaId']) === 0
            && virtualStorageRows($fixture['unknownBetaId']) === 1);
        virtualCheck('whole-app cache cleanup is idempotent', $lifecycle->exclusive(fn (): int => $cleanup->removeAll()) === 0);
        virtualFiles($fixture);
    } else {
        throw new RuntimeException('Unknown private virtual-cache fixture action.');
    }
    file_put_contents($manifest, json_encode($fixture, JSON_THROW_ON_ERROR));
    chmod($manifest, 0600);
    echo json_encode(['status' => 'passed', 'checks' => $checks] + $extra, JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    echo json_encode(['status' => 'failed', 'checks' => $checks, 'errorClass' => $error::class,
        'message' => $error->getMessage(), 'file' => basename($error->getFile()), 'line' => $error->getLine()], JSON_THROW_ON_ERROR) . "\n";
    exit(1);
}
