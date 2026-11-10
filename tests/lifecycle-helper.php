<?php
declare(strict_types=1);

// Test-only CLI helper, outside the application's web root and routes.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
\OC_App::loadApps();

$server = \OC::$server;
$repo = $server->get(\OCA\ArchiveTools\Service\MountRepository::class);
$command = $argv[1] ?? '';
$id = (int)($argv[2] ?? 0);

if ($command === 'cleanup') {
    echo json_encode($server->get(\OCA\ArchiveTools\Service\IdleCleanupService::class)->cleanup(), JSON_THROW_ON_ERROR), PHP_EOL;
} elseif ($command === 'old') {
    $qb = $server->get(\OCP\IDBConnection::class)->getQueryBuilder();
    $qb->update('archive_tools_mounts')->set('last_access', $qb->createNamedParameter(time() - 3600))
        ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeStatement();
    echo json_encode(['aged' => $id], JSON_THROW_ON_ERROR), PHP_EOL;
} elseif ($command === 'state') {
    $mount = $repo->find($id);
    echo json_encode($mount === null ? null : [
        'id' => (int)$mount['id'], 'lastAccess' => (int)$mount['last_access'],
        'pinned' => (bool)$mount['pinned'], 'active' => $repo->hasActiveLeases($id),
    ], JSON_THROW_ON_ERROR), PHP_EOL;
} elseif ($command === 'hold-stream') {
    $uid = $argv[3] ?? '';
    $path = $argv[4] ?? '';
    if (!str_starts_with($uid, 'at_') || !str_starts_with($path, 'Team/')) {
        throw new RuntimeException('This helper only accepts the generated test fixture.');
    }
    $user = $server->get(\OCP\IUserManager::class)->get($uid);
    $server->get(\OCP\IUserSession::class)->setUser($user);
    \OC_Util::setupFS($uid);
    $stream = $server->get(\OCP\Files\IRootFolder::class)->getUserFolder($uid)->get($path)->fopen('rb');
    if (!is_resource($stream)) {
        throw new RuntimeException('The test archive stream could not be opened.');
    }
    try {
        echo "READY\n";
        flush();
        sleep(8);
        if (stream_get_contents($stream) === false) {
            throw new RuntimeException('The held stream could not be read.');
        }
    } finally {
        fclose($stream);
    }
    echo "CLOSED\n";
} else {
    throw new RuntimeException('Unknown lifecycle test helper command.');
}
