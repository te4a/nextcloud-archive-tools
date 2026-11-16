<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** A cache reservation can outlive its connection until cleanup succeeds. */
final class Version000500Date20261116000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('archive_tools_cache')) {
            $table = $schema->createTable('archive_tools_cache');
            $table->addColumn('mount_id', 'bigint', ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('cache_identity', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('cache_path', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('cache_numeric_id', 'bigint', ['notnull' => false]);
            $table->setPrimaryKey(['mount_id']);
        }
        return $schema;
    }
}
