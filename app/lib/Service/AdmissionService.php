<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Service;

use OCP\IConfig;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;

/** Persistent resource admission, serialized with insertion by LifecycleService. */
final class AdmissionService {
    public const MAX_PASSWORD_BYTES = 4095;
    public const HISTORY_BATCH = 200;
    public const DEFAULT_LIMITS = [
        'connections_per_owner' => 16, 'connections_global' => 128,
        'active_jobs_per_owner' => 4, 'active_jobs_global' => 32,
        'history_per_owner' => 200, 'history_global' => 2000,
        'metadata_bytes_per_owner' => 16777216, 'metadata_bytes_global' => 134217728,
    ];
    private const TERMINAL = ['succeeded', 'failed', 'cancelled'];
    private mixed $configuredLimits;

    public function __construct(private IDBConnection $db, IConfig $config, private LifecycleService $lifecycle) {
        $this->configuredLimits = $config->getSystemValue('archive_tools_limits', []);
    }

    public static function validatePassword(#[\SensitiveParameter] ?string $password): void {
        if ($password !== null && strlen($password) > self::MAX_PASSWORD_BYTES) {
            throw new \InvalidArgumentException('The archive password exceeds the supported byte length.');
        }
    }

    /** Trusted server configuration only; ordinary app settings cannot alter it. */
    public function limits(): array {
        $configured = $this->configuredLimits;
        if (!is_array($configured)) { throw new \RuntimeException('The Archive Tools resource limit configuration is invalid.'); }
        $limits = self::DEFAULT_LIMITS;
        foreach ($limits as $key => $default) {
            if (!array_key_exists($key, $configured)) { continue; }
            if (!is_int($configured[$key]) || $configured[$key] < 1) {
                throw new \RuntimeException('The Archive Tools resource limit configuration is invalid.');
            }
            $limits[$key] = $configured[$key];
        }
        return $limits;
    }

    private function byteLength(string $column): string {
        return match ($this->db->getDatabaseProvider()) {
            IDBConnection::PLATFORM_SQLITE => 'LENGTH(CAST(' . $column . ' AS BLOB))',
            IDBConnection::PLATFORM_POSTGRES, IDBConnection::PLATFORM_MYSQL => 'OCTET_LENGTH(' . $column . ')',
            default => throw new \RuntimeException('Archive Tools resource admission is unavailable for this database.'),
        };
    }

    private function bytesExpression(string $json, string $secret): string {
        return $this->byteLength($json) . ' + COALESCE(' . $this->byteLength($secret) . ', 0)';
    }

    private function usage(bool $jobs, ?string $owner): array {
        $table = $jobs ? 'archive_tools_jobs' : 'archive_tools_mounts';
        $ownerColumn = $jobs ? 'owner_id' : 'created_by';
        $bytes = $this->bytesExpression($jobs ? 'plan_json' : 'index_json', $jobs ? 'secret' : 'password');
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) AS row_count'),
            $qb->createFunction('COALESCE(SUM(' . $bytes . '), 0) AS metadata_bytes'))->from($table);
        if ($jobs) {
            $qb->addSelect($qb->createFunction("COALESCE(SUM(CASE WHEN state IN ('queued', 'running') THEN 1 ELSE 0 END), 0) AS active_count"));
        }
        if ($owner !== null) { $qb->where($qb->expr()->eq($ownerColumn, $qb->createNamedParameter($owner))); }
        $row = $qb->executeQuery()->fetchAssociative();
        if ($row === false) { throw new \RuntimeException('Archive Tools resource usage could not be verified.'); }
        $result = [];
        foreach (['row_count', 'metadata_bytes', ...($jobs ? ['active_count'] : [])] as $field) {
            $value = filter_var($row[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($value === false) { throw new \RuntimeException('Archive Tools resource usage could not be verified.'); }
            $result[$field] = $value;
        }
        return $result;
    }

    private function assertBytes(array $mounts, array $jobs, int $added, int $limit, bool $owner): void {
        // Subtraction avoids overflow even if an installation already exceeds
        // its configured ceiling. Existing rows remain available for recovery.
        if ($mounts['metadata_bytes'] > $limit || $jobs['metadata_bytes'] > $limit - $mounts['metadata_bytes']
            || $added > $limit - $mounts['metadata_bytes'] - $jobs['metadata_bytes']) {
            throw new ResourceLimitException($owner
                ? 'Your archive metadata limit has been reached. Disconnect unused archives or ask an administrator to clear completed task history.'
                : 'The server archive metadata limit has been reached. Ask an administrator to disconnect unused archives or clear completed task history.');
        }
    }

    /** Must run inside the same LifecycleService::write callback as INSERT. */
    public function assertConnection(string $owner, string $indexJson = '', #[\SensitiveParameter] ?string $encryptedPassword = null): void {
        $limits = $this->limits();
        $ownMounts = $this->usage(false, $owner);
        if ($ownMounts['row_count'] >= $limits['connections_per_owner']) {
            throw new ResourceLimitException('Your archive connection limit has been reached. Disconnect an unused archive before connecting another.');
        }
        $allMounts = $this->usage(false, null);
        if ($allMounts['row_count'] >= $limits['connections_global']) {
            throw new ResourceLimitException('The server archive connection limit has been reached. Ask an administrator to disconnect unused archives.');
        }
        $added = strlen($indexJson) + strlen($encryptedPassword ?? '');
        $this->assertBytes($ownMounts, $this->usage(true, $owner), $added, $limits['metadata_bytes_per_owner'], true);
        $this->assertBytes($allMounts, $this->usage(true, null), $added, $limits['metadata_bytes_global'], false);
    }

    /** Check active-key deduplication before calling; a retry consumes no slot. */
    public function assertJob(string $owner, string $planJson, #[\SensitiveParameter] ?string $encryptedSecret): void {
        $limits = $this->limits();
        $ownJobs = $this->usage(true, $owner);
        $allJobs = $this->usage(true, null);
        if ($ownJobs['active_count'] >= $limits['active_jobs_per_owner']) {
            throw new ResourceLimitException('Your active archive task limit has been reached. Wait for a task to finish or cancel a queued task.');
        }
        if ($allJobs['active_count'] >= $limits['active_jobs_global']) {
            throw new ResourceLimitException('The server active archive task limit has been reached. Wait for existing tasks to finish.');
        }
        if ($ownJobs['row_count'] >= $limits['history_per_owner']) {
            throw new ResourceLimitException('Your archive task history limit has been reached. Ask an administrator to clear completed task history.');
        }
        if ($allJobs['row_count'] >= $limits['history_global']) {
            throw new ResourceLimitException('The server archive task history limit has been reached. Ask an administrator to clear completed task history.');
        }
        $added = strlen($planJson) + strlen($encryptedSecret ?? '');
        $this->assertBytes($this->usage(false, $owner), $ownJobs, $added, $limits['metadata_bytes_per_owner'], true);
        $this->assertBytes($this->usage(false, null), $allJobs, $added, $limits['metadata_bytes_global'], false);
    }

    /** Explicit administrator recovery only; never compacts automatic history. */
    public function clearTerminalHistory(string $owner, bool $execute = false, array $ids = []): array {
        if ($owner === '' || strlen($owner) > 64 || str_contains($owner, "\0")) { throw new \InvalidArgumentException('An explicit valid task owner is required.'); }
        if (count($ids) > self::HISTORY_BATCH || array_filter($ids, static fn ($id): bool => !is_int($id) || $id < 1)) {
            throw new \InvalidArgumentException('Select at most 200 positive task ids.');
        }
        $operation = function () use ($owner, $execute, $ids): array {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'target_id', $qb->createFunction('(' . $this->bytesExpression('plan_json', 'secret') . ') AS metadata_bytes'))
                ->from('archive_tools_jobs')->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($owner)))
                ->andWhere($qb->expr()->in('state', $qb->createNamedParameter(self::TERMINAL, IQueryBuilder::PARAM_STR_ARRAY)))
                ->orderBy('id')->setMaxResults(self::HISTORY_BATCH + 1);
            if ($ids !== []) { $qb->andWhere($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY))); }
            $rows = $qb->executeQuery()->fetchAllAssociative();
            $more = count($rows) > self::HISTORY_BATCH;
            $rows = array_slice($rows, 0, self::HISTORY_BATCH);
            $selected = array_map(static fn (array $row): int => (int)$row['id'], $rows);
            $result = ['execute' => $execute, 'selectedRows' => count($rows), 'deletedRows' => 0,
                'metadataBytes' => array_sum(array_map(static fn (array $row): int => (int)$row['metadata_bytes'], $rows)),
                'rowsWithOutput' => count(array_filter($rows, static fn (array $row): bool => $row['target_id'] !== null)),
                'moreAvailable' => $more];
            if ($execute && $selected !== []) {
                $qb = $this->db->getQueryBuilder();
                $result['deletedRows'] = $qb->delete('archive_tools_jobs')
                    ->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($owner)))
                    ->andWhere($qb->expr()->in('state', $qb->createNamedParameter(self::TERMINAL, IQueryBuilder::PARAM_STR_ARRAY)))
                    ->andWhere($qb->expr()->in('id', $qb->createNamedParameter($selected, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
            }
            return $result;
        };
        if ($execute) { return $this->lifecycle->write($operation); }
        $this->lifecycle->assertReady();
        return $operation();
    }
}
