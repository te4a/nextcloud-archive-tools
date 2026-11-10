<?php
declare(strict_types=1);

// Real database regression; every fixture mutation is rolled back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
\OC_App::loadApps();

use OCA\ArchiveTools\Service\JobRepository;
use OCA\ArchiveTools\Service\SettingsService;

$db = \OC::$server->get(\OCP\IDBConnection::class);
$repo = \OC::$server->get(JobRepository::class);
$owner = 'archive-tools-retention-test-' . bin2hex(random_bytes(8));
// Existing installation rows are newer than this artificial historical clock.
$now = 1000000000;
$cutoff = $now - 30 * 86400;
$checks = 0;

function retentionCheck(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
    echo 'PASS ' . $message . "\n";
}

function fixture(string $state, int $updated, ?int $target = null, bool $secret = true): int {
    global $repo, $owner, $now;
    return $repo->create([
        'owner_id' => $owner, 'source_id' => -1, 'source_name' => 'retention-fixture.zip',
        'source_etag' => 'retention-fixture', 'destination_id' => -2, 'folder_name' => 'Retained output',
        'plan_json' => '{"member.txt":{"type":"file","originalName":"private raw fixture name"}}',
        'secret' => $secret ? 'test-only-encrypted-credential' : null,
        'active_key' => $secret ? hash('sha256', random_bytes(16)) : null,
        'state' => $state, 'cancel_requested' => 0,
        'files_total' => 1, 'files_done' => 0, 'bytes_total' => 10, 'bytes_done' => 0,
        'created_at' => $now - 400 * 86400, 'updated_at' => $updated,
        'target_id' => $target, 'message' => $target === null ? null : 'Partial output was retained for review.',
    ]);
}

function fixtureCount(): int {
    global $db, $owner;
    $qb = $db->getQueryBuilder();
    return count($qb->select('id')->from('archive_tools_jobs')
        ->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($owner)))
        ->executeQuery()->fetchAllAssociative());
}

$db->beginTransaction();
try {
    $oldSuccess = fixture('succeeded', $cutoff - 1, 1001);
    $oldFailed = fixture('failed', $cutoff - 1);
    $oldCancelled = fixture('cancelled', $cutoff - 1);
    $retainedFailed = fixture('failed', $cutoff - 1, 1002);
    $retainedCancelled = fixture('cancelled', $cutoff - 1, 1003);
    $queued = fixture('queued', $cutoff - 100);
    $running = fixture('running', $cutoff - 100);
    $recent = fixture('succeeded', $now - 86400, 1004);
    $boundary = fixture('succeeded', $cutoff, null, false);
    $unknown = fixture('unknown', $cutoff - 100);
    $before = [];
    foreach ([$retainedFailed, $retainedCancelled, $queued, $running, $recent, $unknown] as $id) { $before[$id] = $repo->find($id); }

    $result = $repo->cleanupHistory(0, $now);
    retentionCheck($result['deleted'] === 0 && fixtureCount() === 10, 'zero retention disables metadata deletion');
    retentionCheck($repo->find($oldSuccess)['secret'] === null && $repo->find($recent)['secret'] === null,
        'terminal credentials are cleared even while retention is disabled');
    foreach ([$retainedFailed, $retainedCancelled] as $id) {
        $row = $repo->find($id);
        retentionCheck($row['secret'] === null && $row['active_key'] === null
            && (int)$row['updated_at'] === (int)$before[$id]['updated_at']
            && $row['plan_json'] === $before[$id]['plan_json']
            && (int)$row['target_id'] === (int)$before[$id]['target_id'],
            'partial-output history is preserved and scrubbed without changing its age');
    }
    foreach ([$queued, $running, $unknown] as $id) {
        retentionCheck($repo->find($id) === $before[$id], 'active or unknown states keep their credentials and complete row');
    }

    $result = $repo->cleanupHistory(30, $now);
    retentionCheck($result['deleted'] === 3, 'expired succeeded failed and cancelled metadata is deleted');
    retentionCheck($repo->find($oldSuccess) === null, 'successful history can expire without following its output id');
    retentionCheck($repo->find($oldFailed) === null && $repo->find($oldCancelled) === null, 'failed and cancelled rows without partial output expire');
    retentionCheck($repo->find($retainedFailed) !== null && $repo->find($retainedCancelled) !== null, 'failed and cancelled partial-output history remains available for review');
    retentionCheck($repo->find($recent) !== null, 'retention uses completion activity rather than an old creation timestamp');
    retentionCheck($repo->find($boundary) !== null, 'the exact age boundary is retained');
    retentionCheck($repo->cleanupHistory(30, $now) === ['deleted' => 0, 'secretsCleared' => 0], 'repeated cleanup is idempotent');

    foreach ([-1, SettingsService::MAX_JOBS_RETENTION_DAYS + 1] as $invalid) {
        $rejected = false;
        try { $repo->cleanupHistory($invalid, $now); }
        catch (InvalidArgumentException) { $rejected = true; }
        retentionCheck($rejected && fixtureCount() === 7, 'invalid retention is rejected before mutation');
    }

    for ($index = 0; $index < JobRepository::HISTORY_CLEANUP_BATCH + 5; ++$index) {
        fixture('succeeded', $cutoff - 10, null, false);
    }
    $result = $repo->cleanupHistory(30, $now);
    retentionCheck($result['deleted'] === 200 && fixtureCount() === 12, 'one purge removes at most two hundred oldest rows');
    retentionCheck($repo->cleanupHistory(30, $now)['deleted'] === 5 && fixtureCount() === 7, 'the next purge drains the remaining history batch');

    $protected = [];
    for ($index = 0; $index < JobRepository::HISTORY_CLEANUP_BATCH + 5; ++$index) {
        $protected[] = fixture('failed', $cutoff - 10, 2000 + $index);
    }
    $result = $repo->cleanupHistory(0, $now);
    $remainingSecrets = 0;
    foreach ($protected as $id) { if ($repo->find($id)['secret'] !== null) { ++$remainingSecrets; } }
    retentionCheck($result['secretsCleared'] === 200 && $remainingSecrets === 5, 'credential repair is bounded to two hundred terminal rows');
    retentionCheck($repo->cleanupHistory(0, $now)['secretsCleared'] === 5 && fixtureCount() === 212,
        'credential repair drains later without deleting partial-output history');
    foreach ([$queued, $running, $unknown] as $id) {
        retentionCheck($repo->find($id) === $before[$id], 'bounded cleanup leaves active and unknown jobs unchanged');
    }
    echo 'All ' . $checks . " job retention tests passed.\n";
} finally {
    $db->rollBack();
}
