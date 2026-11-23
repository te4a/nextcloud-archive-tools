<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000500Date20261124000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        $table = $schema->getTable('archive_tools_mounts');
        if (!$table->hasColumn('preview_depth')) {
            $table->addColumn('preview_depth', 'smallint', ['notnull' => true, 'default' => 0]);
        }
        return $schema;
    }
}
