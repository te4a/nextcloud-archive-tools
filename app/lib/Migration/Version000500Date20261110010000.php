<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** A nonsecret durable cleanup tombstone must outlive app configuration. */
final class Version000500Date20261110010000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('archive_tools_state')) {
            $table = $schema->createTable('archive_tools_state');
            $table->addColumn('id', 'smallint', ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('state', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('updated_at', 'bigint', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
        }
        return $schema;
    }
}
