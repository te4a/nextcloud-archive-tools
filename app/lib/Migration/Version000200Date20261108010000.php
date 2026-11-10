<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000200Date20261108010000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('archive_tools_jobs')) {
            $table = $schema->createTable('archive_tools_jobs');
            $table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('owner_id', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('source_id', 'bigint', ['notnull' => true]);
            $table->addColumn('source_name', 'string', ['length' => 255, 'notnull' => true]);
            $table->addColumn('source_etag', 'string', ['length' => 128, 'notnull' => true]);
            $table->addColumn('destination_id', 'bigint', ['notnull' => true]);
            $table->addColumn('folder_name', 'string', ['length' => 255, 'notnull' => true]);
            $table->addColumn('plan_json', 'text', ['notnull' => true]);
            $table->addColumn('secret', 'text', ['notnull' => false]);
            $table->addColumn('active_key', 'string', ['length' => 64, 'notnull' => false]);
            $table->addColumn('state', 'string', ['length' => 16, 'notnull' => true]);
            $table->addColumn('cancel_requested', 'boolean', ['default' => false, 'notnull' => true]);
            foreach (['files_total', 'files_done', 'bytes_total', 'bytes_done', 'created_at', 'updated_at'] as $column) {
                $table->addColumn($column, 'bigint', ['notnull' => true, 'default' => 0]);
            }
            $table->addColumn('target_id', 'bigint', ['notnull' => false]);
            $table->addColumn('message', 'string', ['length' => 255, 'notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['active_key'], 'at_job_active');
            $table->addIndex(['owner_id', 'created_at'], 'at_job_owner');
        }
        return $schema;
    }
}
