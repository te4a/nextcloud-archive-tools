<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Service;

use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;

/** Durable job state; secrets and raw archive names never enter the public DTO. */
final class JobRepository {
    public const HISTORY_CLEANUP_BATCH = 200;
    private const TERMINAL_STATES = ['succeeded', 'failed', 'cancelled'];

    public function __construct(private IDBConnection $db) {}

    /**
     * Remove old history metadata only; never inspect or delete output files.
     * Failed/cancelled rows with retained output stay available for review.
     * Secret repair is independent of retention and never changes job age.
     *
     * @return array{deleted:int,secretsCleared:int}
     */
    public function cleanupHistory(int $retentionDays, ?int $now = null): array {
        if ($retentionDays < 0 || $retentionDays > SettingsService::MAX_JOBS_RETENTION_DAYS) {
            throw new \InvalidArgumentException('The task history retention must be a whole number between 0 and 3650 days.');
        }
        $now ??= time();
        $result = ['deleted' => 0, 'secretsCleared' => 0];

        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('archive_tools_jobs')
            ->where($this->terminalStates($qb))
            ->andWhere($qb->expr()->orX($qb->expr()->isNotNull('secret'), $qb->expr()->isNotNull('active_key')))
            ->orderBy('id')->setMaxResults(self::HISTORY_CLEANUP_BATCH);
        $ids = array_map('intval', $qb->executeQuery()->fetchFirstColumn());
        if ($ids !== []) {
            $qb = $this->db->getQueryBuilder();
            $result['secretsCleared'] = $qb->update('archive_tools_jobs')
                ->set('secret', $qb->createNamedParameter(null))
                ->set('active_key', $qb->createNamedParameter(null))
                ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($this->terminalStates($qb))
                ->andWhere($qb->expr()->orX($qb->expr()->isNotNull('secret'), $qb->expr()->isNotNull('active_key')))
                ->executeStatement();
        }

        if ($retentionDays === 0) { return $result; }
        $cutoff = $now - $retentionDays * 86400;
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('archive_tools_jobs')->orderBy('updated_at')->addOrderBy('id')
            ->setMaxResults(self::HISTORY_CLEANUP_BATCH);
        $this->filterExpiredHistory($qb, $cutoff);
        $ids = array_map('intval', $qb->executeQuery()->fetchFirstColumn());
        if ($ids !== []) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('archive_tools_jobs')
                ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
            // Recheck every condition at deletion, even when another cleanup
            // process or future retry operation changed a selected row.
            $this->filterExpiredHistory($qb, $cutoff);
            $result['deleted'] = $qb->executeStatement();
        }
        return $result;
    }

    private function terminalStates(IQueryBuilder $qb) {
        return $qb->expr()->in('state', $qb->createNamedParameter(self::TERMINAL_STATES, IQueryBuilder::PARAM_STR_ARRAY));
    }

    private function filterExpiredHistory(IQueryBuilder $qb, int $cutoff): void {
        $qb->andWhere($this->terminalStates($qb))
            ->andWhere($qb->expr()->lt('updated_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->eq('state', $qb->createNamedParameter('succeeded')),
                $qb->expr()->isNull('target_id'),
            ));
    }

    public function find(int $id): ?array {
        $qb = $this->db->getQueryBuilder();
        $row = $qb->select('*')->from('archive_tools_jobs')->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeQuery()->fetchAssociative();
        return $row === false ? null : $row;
    }

    public function findActive(string $key): ?array {
        $qb = $this->db->getQueryBuilder();
        $row = $qb->select('*')->from('archive_tools_jobs')->where($qb->expr()->eq('active_key', $qb->createNamedParameter($key)))->executeQuery()->fetchAssociative();
        return $row === false ? null : $row;
    }

    public function forOwner(string $owner): array {
        $qb = $this->db->getQueryBuilder();
        // History polling must not load or decode the potentially large plans.
        return $qb->select('id', 'state', 'source_id', 'source_name', 'destination_id', 'folder_name',
            'files_total', 'files_done', 'bytes_total', 'bytes_done', 'created_at', 'updated_at',
            'target_id', 'message', 'cancel_requested')
            ->from('archive_tools_jobs')->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($owner)))
            ->orderBy('id', 'DESC')->setMaxResults(100)->executeQuery()->fetchAllAssociative();
    }

    public function pending(): array {
        $qb = $this->db->getQueryBuilder();
        return $qb->select('id')->from('archive_tools_jobs')->where($qb->expr()->eq('state', $qb->createNamedParameter('queued')))
            ->orderBy('id')->setMaxResults(100)->executeQuery()->fetchAllAssociative();
    }

    /** A crashed worker cannot safely delete output another user may have edited. */
    public function recoverInterrupted(?string $owner = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'owner_id', 'source_name', 'target_id')->from('archive_tools_jobs')->where($qb->expr()->eq('state', $qb->createNamedParameter('running')))
            ->andWhere($qb->expr()->lt('updated_at', $qb->createNamedParameter(time() - 900)));
        if ($owner !== null) { $qb->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($owner))); }
        $rows = $qb->executeQuery()->fetchAllAssociative();
        $recovered = [];
        foreach ($rows as $row) {
            $qb = $this->db->getQueryBuilder();
            $changed = $qb->update('archive_tools_jobs')->set('state', $qb->createNamedParameter('failed'))
                ->set('message', $qb->createNamedParameter('Extraction was interrupted. Any partial output was retained for review.'))
                ->set('active_key', $qb->createNamedParameter(null))->set('secret', $qb->createNamedParameter(null))->set('updated_at', $qb->createNamedParameter(time()))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($row['id'])))
                ->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('running')))
                ->andWhere($qb->expr()->lt('updated_at', $qb->createNamedParameter(time() - 900)))->executeStatement();
            if ($changed === 1) { $recovered[] = $row; }
        }
        return $recovered;
    }

    public function create(array $values): int {
        $qb = $this->db->getQueryBuilder();
        $parameters = [];
        foreach ($values as $key => $value) { $parameters[$key] = $qb->createNamedParameter($value); }
        $qb->insert('archive_tools_jobs')->values($parameters)->executeStatement();
        return (int)$qb->getLastInsertId();
    }

    public function update(int $id, array $values): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('archive_tools_jobs');
        foreach ($values + ['updated_at' => time()] as $key => $value) { $qb->set($key, $qb->createNamedParameter($value)); }
        $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeStatement();
    }

    public function claim(int $id): bool {
        $qb = $this->db->getQueryBuilder();
        return $qb->update('archive_tools_jobs')->set('state', $qb->createNamedParameter('running'))
            ->set('updated_at', $qb->createNamedParameter(time()))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('queued')))->executeStatement() === 1;
    }

    public function cancel(int $id): void {
        $row = $this->find($id);
        if ($row === null || !in_array($row['state'], ['queued', 'running'], true)) { return; }
        if ($row['state'] === 'queued') {
            // Conditional update avoids cancelling a newly started job without
            // allowing its worker to perform the rollback itself.
            $qb = $this->db->getQueryBuilder();
            $changed = $qb->update('archive_tools_jobs')->set('state', $qb->createNamedParameter('cancelled'))
                ->set('cancel_requested', $qb->createNamedParameter(1))->set('secret', $qb->createNamedParameter(null))
                ->set('active_key', $qb->createNamedParameter(null))->set('updated_at', $qb->createNamedParameter(time()))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
                ->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('queued')))->executeStatement();
            if ($changed === 1) { return; }
        }
        $qb = $this->db->getQueryBuilder();
        $qb->update('archive_tools_jobs')->set('cancel_requested', $qb->createNamedParameter(1))
            ->set('updated_at', $qb->createNamedParameter(time()))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('running')))->executeStatement();
    }

    public static function dto(array $row, bool $includeMembers = true): array {
        $dto = [
            'id' => (int)$row['id'], 'status' => $row['state'],
            'sourceId' => (int)$row['source_id'], 'sourceName' => $row['source_name'],
            'destinationId' => (int)$row['destination_id'], 'folderName' => $row['folder_name'],
            'filesTotal' => (int)$row['files_total'], 'filesDone' => (int)$row['files_done'],
            'bytesTotal' => (int)$row['bytes_total'], 'bytesDone' => (int)$row['bytes_done'],
            'createdAt' => (int)$row['created_at'], 'updatedAt' => (int)$row['updated_at'],
            'targetId' => $row['target_id'] === null ? null : (int)$row['target_id'],
            'message' => $row['message'], 'cancelRequested' => (bool)$row['cancel_requested'],
        ];
        if ($includeMembers) {
            $plan = json_decode($row['plan_json'], true);
            $dto['members'] = is_array($plan) ? array_map('strval', array_keys($plan)) : [];
        }
        return $dto;
    }
}
