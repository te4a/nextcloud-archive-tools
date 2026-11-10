<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000100Date20261108000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('archive_tools_mounts')) {
            $table = $schema->createTable('archive_tools_mounts');
            $table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('source_id', 'bigint', ['notnull' => true]);
            $table->addColumn('parent_id', 'bigint', ['notnull' => true]);
            $table->addColumn('mount_name', 'string', ['notnull' => true, 'length' => 255]);
            $table->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('pinned', 'boolean', ['notnull' => true, 'default' => false]);
            $table->addColumn('last_access', 'bigint', ['notnull' => true]);
            $table->addColumn('source_etag', 'string', ['notnull' => true, 'length' => 128]);
            $table->addColumn('index_json', 'text', ['notnull' => true]);
            $table->addColumn('password', 'text', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['parent_id', 'mount_name'], 'at_parent_name');
            $table->addIndex(['source_id'], 'at_source');
        }
        return $schema;
    }
}
