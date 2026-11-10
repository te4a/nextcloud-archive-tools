<?php
declare(strict_types=1);

// Test-only policy injection avoids writing the server's config.php. Callers
// bootstrap core, use generated owners and roll back/delete only their rows.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }

use OCA\ArchiveTools\Service\AdmissionService;
use OCA\ArchiveTools\Service\JobRepository;
use OCA\ArchiveTools\Service\LifecycleService;

function admissionFixtureService(\OCP\IDBConnection $db, array $limits): AdmissionService {
    $type = new ReflectionClass(AdmissionService::class);
    $service = $type->newInstanceWithoutConstructor();
    foreach (['db' => $db, 'configuredLimits' => $limits,
        'lifecycle' => \OCP\Server::get(LifecycleService::class)] as $name => $value) {
        $type->getProperty($name)->setValue($service, $value);
    }
    return $service;
}

function admissionFixtureOwner(string $owner): void {
    if (!preg_match('/^at_admission_[a-f0-9]{24}_(alice|bob)$/D', $owner)) {
        throw new RuntimeException('Only generated admission fixture owners are allowed.');
    }
}

function admissionFixtureJob(\OCP\IDBConnection $db, string $owner, string $state = 'queued', string $plan = '{"one.txt":{}}', ?string $secret = null, ?int $target = null): int {
    admissionFixtureOwner($owner);
    $now = time();
    return (new JobRepository($db))->create([
        'owner_id' => $owner, 'source_id' => -1, 'source_name' => 'admission-fixture.zip', 'source_etag' => 'owned-fixture',
        'destination_id' => -2, 'folder_name' => 'Admission output', 'plan_json' => $plan, 'secret' => $secret,
        'active_key' => in_array($state, ['queued', 'running'], true) ? hash('sha256', random_bytes(16)) : null,
        'state' => $state, 'cancel_requested' => 0, 'files_total' => 1, 'files_done' => 0, 'bytes_total' => 0, 'bytes_done' => 0,
        'created_at' => $now, 'updated_at' => $now, 'target_id' => $target, 'message' => null,
    ]);
}

function admissionFixtureMount(\OCP\IDBConnection $db, string $owner, string $index = '{}', ?string $secret = null): int {
    admissionFixtureOwner($owner);
    $qb = $db->getQueryBuilder();
    $values = ['source_id' => -1, 'parent_id' => -2, 'mount_name' => 'admission-' . bin2hex(random_bytes(10)),
        'created_by' => $owner, 'pinned' => 1, 'last_access' => time(), 'source_etag' => 'owned-fixture', 'index_json' => $index, 'password' => $secret];
    $parameters = [];
    foreach ($values as $name => $value) { $parameters[$name] = $qb->createNamedParameter($value); }
    $qb->insert('archive_tools_mounts')->values($parameters)->executeStatement();
    return (int)$qb->getLastInsertId();
}

function admissionFixtureRemove(\OCP\IDBConnection $db, string $owner): void {
    admissionFixtureOwner($owner);
    foreach (['archive_tools_mounts' => 'created_by', 'archive_tools_jobs' => 'owner_id'] as $table => $column) {
        $qb = $db->getQueryBuilder();
        $qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter($owner)))->executeStatement();
    }
}
