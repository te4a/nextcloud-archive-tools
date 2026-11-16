<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Repair;

use OCA\ArchiveTools\Service\JobRepository;
use OCA\ArchiveTools\Service\LifecycleService;
use OCA\ArchiveTools\Service\MetadataOnlyStorage;
use OCA\ArchiveTools\Service\MountRepository;
use OCA\ArchiveTools\Service\VirtualStorageCleanup;
use OCA\ArchiveTools\Storage\ArchiveStorage;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/** Install/upgrade only. Ordinary disable must preserve settings and pins. */
final class RepairApplicationState implements IRepairStep {
    public function __construct(
        private LifecycleService $lifecycle,
        private JobRepository $jobs,
        private MountRepository $mounts,
        private VirtualStorageCleanup $virtualStorage,
    ) {}

    public function getName(): string { return 'Repair Archive Tools state and preserve existing archive cache routes'; }

    public function run(IOutput $output): void {
        $this->lifecycle->initialize();
        $result = $this->jobs->cleanupHistory(0);
        $output->info('Archive Tools lifecycle state is ready. Terminal task credentials cleared: ' . $result['secretsCleared'] . '.');
        $adopted = $this->lifecycle->exclusive(function (): int {
            $untracked = array_filter($this->mounts->findAll(), static fn (array $row): bool => ($row['cache_identity'] ?? null) === null);
            if ($untracked === []) {
                return 0;
            }
            $legacyIds = $this->virtualStorage->legacyStorageIds($untracked);
            $previousPaths = $this->virtualStorage->previousSharedPaths($untracked);
            foreach ($untracked as $row) {
                $id = (int)$row['id'];
                if (!isset($legacyIds[$id]) && isset($previousPaths[$id])) {
                    $this->mounts->reserveCache($id, ArchiveStorage::STORAGE_ID, $previousPaths[$id]);
                } else {
                    $this->mounts->reserveCache($id, MetadataOnlyStorage::legacyIdentity($id, (string)$row['source_etag']), '', $legacyIds[$id] ?? null);
                }
            }
            return count($untracked);
        });
        if ($adopted > 0) {
            $output->info('Existing Archive Tools cache routes recorded: ' . $adopted . '.');
        }
    }
}
