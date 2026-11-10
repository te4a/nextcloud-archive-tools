<?php
declare(strict_types=1);

// Isolated repository regression: all fixture rows are rolled back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
\OC_App::loadApps();

$db = \OC::$server->get(\OCP\IDBConnection::class);
$repo = \OC::$server->get(\OCA\ArchiveTools\Service\MountRepository::class);
$index = json_encode(['entries' => ['fixture.txt' => ['originalName' => str_repeat('x', 1024 * 1024)]]], JSON_THROW_ON_ERROR);
$secret = 'test-only-encrypted-credential';
$db->beginTransaction();
try {
    $id = $repo->create([
        'source_id' => -1, 'parent_id' => -1, 'mount_name' => 'projection-test-' . bin2hex(random_bytes(8)),
        'created_by' => 'archive-tools-projection-test', 'pinned' => 0, 'last_access' => time(),
        'source_etag' => 'projection-test', 'index_json' => $index, 'password' => $secret,
    ]);
    $projection = null;
    foreach ($repo->findAll() as $row) {
        if ((int)$row['id'] === $id) { $projection = $row; break; }
    }
    if ($projection === null || isset($projection['index_json']) || isset($projection['password'])
        || strlen(json_encode($projection, JSON_THROW_ON_ERROR)) > 4096) {
        throw new RuntimeException('A large archive must not expand global mount enumeration or expose its credential.');
    }
    echo "PASS global mount enumeration stays small and excludes archive indexes and credentials\n";

    $details = $repo->find($id);
    if ($details === null || $details['index_json'] !== $index || $details['password'] !== $secret
        || $details['source_etag'] !== $projection['source_etag']) {
        throw new RuntimeException('A fresh lookup must retain the complete index and credential for authorized storage access.');
    }
    echo "PASS individual lookup retains complete archive metadata for authorized access\n";
} finally {
    $db->rollBack();
}
