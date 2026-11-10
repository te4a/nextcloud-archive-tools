<?php
declare(strict_types=1);

// Real SQL regressions on each declared database. Every fixture is rolled back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
\OC_App::loadApps();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'FAIL admission regression: ' . $error::class . ': ' . substr($error->getMessage(), 0, 700) . "\n");
    exit(1);
});
require __DIR__ . '/admission-fixtures.php';

use OCA\ArchiveTools\Service\AdmissionService;
use OCA\ArchiveTools\Service\JobRepository;
use OCA\ArchiveTools\Service\LifecycleService;
use OCA\ArchiveTools\Service\ResourceLimitException;

$db = \OCP\Server::get(\OCP\IDBConnection::class);
$lifecycle = \OCP\Server::get(LifecycleService::class);
$repo = new JobRepository($db);
$token = bin2hex(random_bytes(12));
$alice = 'at_admission_' . $token . '_alice';
$bob = 'at_admission_' . $token . '_bob';
$checks = 0;
// Leave ample room for pre-existing installation rows without changing policy.
$roomy = array_fill_keys(array_keys(AdmissionService::DEFAULT_LIMITS), 1073741824);

function admissionCheck(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
    echo 'PASS ' . $message . "\n";
}
function admissionReject(callable $operation, string $fragment, string $name): void {
    try { $operation(); }
    catch (ResourceLimitException $error) {
        admissionCheck(str_contains($error->getMessage(), $fragment), $name);
        return;
    }
    throw new RuntimeException($name . ': admission unexpectedly succeeded.');
}
function admissionCount(string $table, ?string $state = null): int {
    global $db;
    $qb = $db->getQueryBuilder();
    $qb->select($qb->func()->count('*'))->from($table);
    if ($state !== null) { $qb->where($qb->expr()->in('state', $qb->createNamedParameter(['queued', 'running'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY))); }
    return (int)$qb->executeQuery()->fetchOne();
}
function admissionReset(): void {
    global $db, $alice, $bob;
    admissionFixtureRemove($db, $alice);
    admissionFixtureRemove($db, $bob);
}

$db->beginTransaction();
try {
    $service = admissionFixtureService($db, $roomy + []);
    admissionCheck($service->limits() === $roomy, 'trusted positive configured ceilings are used');
    foreach ([0, -1, '16', null] as $invalid) {
        $rejected = false;
        try { admissionFixtureService($db, ['connections_per_owner' => $invalid])->limits(); }
        catch (RuntimeException) { $rejected = true; }
        admissionCheck($rejected, 'invalid configured ceilings fail closed');
    }
    admissionCheck(admissionFixtureService($db, [])->limits() === AdmissionService::DEFAULT_LIMITS, 'documented defaults are complete and stable');

    $service = admissionFixtureService($db, array_replace($roomy, ['connections_per_owner' => 2]));
    admissionFixtureMount($db, $alice);
    $service->assertConnection($alice, '{}');
    admissionCheck(true, 'last owner connection slot remains available');
    admissionFixtureMount($db, $alice);
    admissionReject(fn () => $service->assertConnection($alice, '{}'), 'Your archive connection limit', 'owner connection ceiling rejects another row');
    $service->assertConnection($bob, '{}');
    admissionCheck(true, 'another owner retains their connection allowance');
    admissionReset();

    $baseline = admissionCount('archive_tools_mounts');
    $service = admissionFixtureService($db, array_replace($roomy, ['connections_global' => $baseline + 2]));
    admissionFixtureMount($db, $alice); admissionFixtureMount($db, $bob);
    admissionReject(fn () => $service->assertConnection($alice, '{}'), 'server archive connection limit', 'global connections count all owners');
    admissionReset();

    $service = admissionFixtureService($db, array_replace($roomy, ['active_jobs_per_owner' => 2]));
    admissionFixtureJob($db, $alice, 'queued'); admissionFixtureJob($db, $alice, 'running'); admissionFixtureJob($db, $alice, 'succeeded');
    admissionReject(fn () => $service->assertJob($alice, '{}', null), 'Your active archive task limit', 'queued and running both consume active owner allowance');
    $service->assertJob($bob, '{}', null);
    admissionCheck(true, 'terminal history and another owner do not consume own active allowance');
    admissionReset();
    $baseline = admissionCount('archive_tools_jobs', 'active');
    $service = admissionFixtureService($db, array_replace($roomy, ['active_jobs_global' => $baseline + 2]));
    admissionFixtureJob($db, $alice, 'queued'); admissionFixtureJob($db, $bob, 'running');
    admissionReject(fn () => $service->assertJob($alice, '{}', null), 'server active archive task limit', 'global active ceiling includes other owners');
    admissionReset();

    $service = admissionFixtureService($db, array_replace($roomy, ['history_per_owner' => 3]));
    $churn = [];
    for ($index = 0; $index < 3; ++$index) {
        $lifecycle->write(function () use ($service, $db, $alice, &$churn): void {
            $service->assertJob($alice, '{"one.txt":{}}', null);
            $churn[] = admissionFixtureJob($db, $alice);
        });
        $repo->cancel(end($churn));
    }
    admissionReject(fn () => $service->assertJob($alice, '{}', null), 'task history limit', 'queued cancellation churn reaches retained-history ceiling');
    admissionCheck(JobRepository::dto($repo->find($churn[0]))['members'] === ['one.txt'], 'admission keeps retry members intact in terminal history');
    $before = array_map(fn (int $id): array => $repo->find($id), $churn);
    $repo->cleanupHistory(0);
    admissionCheck($before === array_map(fn (int $id): array => $repo->find($id), $churn), 'retention zero leaves completed metadata and retry selections unchanged');
    admissionReset();
    $baseline = admissionCount('archive_tools_jobs');
    $service = admissionFixtureService($db, array_replace($roomy, ['history_global' => $baseline + 2]));
    admissionFixtureJob($db, $alice, 'cancelled'); admissionFixtureJob($db, $bob, 'failed');
    admissionReject(fn () => $service->assertJob($alice, '{}', null), 'server archive task history limit', 'global retained-history ceiling includes every owner');
    admissionReset();

    $json = '{"Юникод🙂":{}}';
    $ciphertext = 'encrypted-credential-🙂';
    $used = strlen($json) + strlen($ciphertext);
    admissionFixtureMount($db, $alice, $json, $ciphertext);
    admissionFixtureJob($db, $alice, 'failed', $json, $ciphertext);
    $service = admissionFixtureService($db, array_replace($roomy, ['metadata_bytes_per_owner' => 2 * $used + 2]));
    $service->assertJob($alice, '{}', null);
    admissionCheck(true, 'Unicode index plan and encrypted credentials fit the exact byte boundary');
    admissionReject(fn () => $service->assertJob($alice, '{}', 'x'), 'Your archive metadata limit', 'one byte beyond combined owner metadata ceiling is rejected');
    $service->assertConnection($bob, '{}');
    admissionCheck(true, 'owner byte allowance excludes other owners stored metadata');
    // A zero ceiling offset service gives the real aggregate without transferring
    // blobs: compare global allowance to the known fixture byte total when fresh.
    $usageMethod = (new ReflectionClass(AdmissionService::class))->getMethod('usage');
    $allMounts = $usageMethod->invoke($service, false, null);
    $allJobs = $usageMethod->invoke($service, true, null);
    $globalBytes = $allMounts['metadata_bytes'] + $allJobs['metadata_bytes'];
    admissionCheck($globalBytes >= 2 * $used, 'database byte aggregation includes multibyte content and stored credentials');
    $service = admissionFixtureService($db, array_replace($roomy, ['metadata_bytes_global' => $globalBytes + 2]));
    $service->assertConnection($bob, '{}');
    admissionCheck(true, 'global metadata ceiling accepts its exact last bytes');
    admissionReject(fn () => $service->assertConnection($bob, '{}', 'x'), 'server archive metadata limit', 'global byte ceiling rejects metadata from any owner');
    admissionReset();

    $service = admissionFixtureService($db, $roomy);
    $terminal = admissionFixtureJob($db, $alice, 'failed', $json, null, -200);
    $queued = admissionFixtureJob($db, $alice, 'queued');
    $running = admissionFixtureJob($db, $alice, 'running');
    $foreign = admissionFixtureJob($db, $bob, 'succeeded');
    $unknown = admissionFixtureJob($db, $alice, 'unknown');
    $before = [$repo->find($terminal), $repo->find($queued), $repo->find($running), $repo->find($foreign), $repo->find($unknown)];
    $stateBefore = $lifecycle->state();
    $preview = $service->clearTerminalHistory($alice);
    admissionCheck($preview['selectedRows'] === 1 && $preview['metadataBytes'] === strlen($json) && $preview['rowsWithOutput'] === 1 && $preview['deletedRows'] === 0,
        'history preview reports exact owned terminal bytes and retained output references');
    admissionCheck($stateBefore === $lifecycle->state() && $before === [$repo->find($terminal), $repo->find($queued), $repo->find($running), $repo->find($foreign), $repo->find($unknown)],
        'preview performs no lifecycle or task metadata mutation');
    $result = $service->clearTerminalHistory($alice, true, [$terminal, $queued, $foreign]);
    admissionCheck($result['deletedRows'] === 1 && $repo->find($terminal) === null, 'explicit recovery deletes only selected terminal owner metadata');
    admissionCheck([$repo->find($queued), $repo->find($running), $repo->find($foreign), $repo->find($unknown)] === array_slice($before, 1),
        'recovery preserves active foreign and unknown-state jobs');
    admissionReset();
    for ($index = 0; $index < AdmissionService::HISTORY_BATCH + 5; ++$index) { admissionFixtureJob($db, $alice, 'succeeded'); }
    $result = $service->clearTerminalHistory($alice, true);
    admissionCheck($result['deletedRows'] === 200 && $result['moreAvailable'], 'explicit terminal cleanup is bounded to two hundred rows');
    admissionCheck($service->clearTerminalHistory($alice, true)['deletedRows'] === 5, 'a later explicit cleanup drains the remaining terminal batch');
    echo 'All ' . $checks . " admission tests passed.\n";
} finally { $db->rollBack(); }
