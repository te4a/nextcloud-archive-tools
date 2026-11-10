<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000200Date20261108020000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('archive_tools_leases')) {
            $table = $schema->createTable('archive_tools_leases');
            $table->addColumn('token', 'string', ['notnull' => true, 'length' => 48]);
            $table->addColumn('mount_id', 'bigint', ['notnull' => true]);
            $table->addColumn('expires_at', 'bigint', ['notnull' => true]);
            $table->setPrimaryKey(['token']);
            $table->addIndex(['mount_id', 'expires_at'], 'at_lease_mount_expiry');
            $table->addIndex(['expires_at'], 'at_lease_expiry');
        }
        return $schema;
    }
}
