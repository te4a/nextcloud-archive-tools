<?php
declare(strict_types=1);

use OCA\ArchiveTools\Service\JobRepository;

$appPath = getenv('ARCHIVE_TOOLS_APP_PATH') ?: dirname(__DIR__) . '/app';
require_once $appPath . '/lib/Service/JobRepository.php';

$row = [
    'id' => '7', 'state' => 'failed', 'source_id' => '11', 'source_name' => 'archive.zip',
    'destination_id' => '12', 'folder_name' => 'Extracted', 'files_total' => '2', 'files_done' => '1',
    'bytes_total' => '10', 'bytes_done' => '5', 'created_at' => '100', 'updated_at' => '101',
    'target_id' => null, 'message' => 'Extraction failed.', 'cancel_requested' => '0',
    'secret' => 'private encrypted credential',
];
$summary = JobRepository::dto($row, false);
if (isset($summary['members']) || isset($summary['secret']) || isset($summary['plan_json'])
    || $summary['id'] !== 7 || $summary['cancelRequested'] !== false) {
    throw new RuntimeException('The history DTO must remain small and exclude credentials.');
}
echo "PASS history DTO requires no extraction plan and exposes no credentials\n";

$row['plan_json'] = json_encode([
    '2026' => ['type' => 'file', 'originalName' => 'private raw archive name'],
    'nested/file.txt' => ['type' => 'file', 'originalName' => 'another raw archive name'],
], JSON_THROW_ON_ERROR);
$details = JobRepository::dto($row);
if ($details['members'] !== ['2026', 'nested/file.txt']
    || str_contains(json_encode($details, JSON_THROW_ON_ERROR), 'raw archive name')
    || isset($details['secret']) || isset($details['plan_json'])) {
    throw new RuntimeException('The detailed DTO must restore normalized selection keys without private metadata.');
}
echo "PASS detailed DTO restores selection without raw names or credentials\n";
