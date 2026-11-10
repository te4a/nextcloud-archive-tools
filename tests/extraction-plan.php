<?php
declare(strict_types=1);

use OCA\ArchiveTools\Service\ArchiveService;

$app = getenv('ARCHIVE_TOOLS_APP_PATH') ?: dirname(__DIR__) . '/app';
require_once $app . '/lib/Service/ArchiveService.php';
$service = (new ReflectionClass(ArchiveService::class))->newInstanceWithoutConstructor();
$index = ['entries' => [
    'a.txt' => ['type' => 'file'],
    '2026' => ['type' => 'file'],
    'foo' => ['type' => 'dir'],
    'foo/a.txt' => ['type' => 'file'],
    'foo/empty' => ['type' => 'dir'],
    'foobar' => ['type' => 'dir'],
    'foobar/other.txt' => ['type' => 'file'],
]];
$checks = 0;
$check = static function (bool $ok, string $name) use (&$checks): void {
    if (!$ok) { throw new RuntimeException($name); }
    $checks++;
    echo 'PASS ', $name, PHP_EOL;
};
$check($service->select($index, []) === $index['entries'], 'empty selection means the complete archive');
$check(array_keys($service->select($index, ['foo'])) === ['foo', 'foo/a.txt', 'foo/empty'], 'directory selection preserves empty directories and exact subtree');
$check(array_keys($service->select($index, ['a.txt', 'foo/a.txt', 'foo'])) === ['a.txt', 'foo', 'foo/a.txt', 'foo/empty'], 'overlapping selections are deduplicated without broadening');
$check(isset($service->select($index, ['2026'])[2026]), 'numeric member names remain valid strings in requests');
foreach ([['missing.txt'], ['../a.txt'], ['foo/../a.txt'], ['/a.txt'], ['foo\\a.txt'], [2026], [null], [str_repeat('x', 100)]] as $invalid) {
    $rejected = false;
    try { $service->select($index, $invalid); }
    catch (InvalidArgumentException) { $rejected = true; }
    $check($rejected, 'invalid or missing selection is rejected before output creation');
}
echo 'All ', $checks, ' extraction plan tests passed.', PHP_EOL;
