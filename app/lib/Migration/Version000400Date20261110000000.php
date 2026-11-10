<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000400Date20261110000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        $table = $schema->getTable('archive_tools_jobs');
        if (!$table->hasIndex('at_job_retention')) { $table->addIndex(['state', 'updated_at', 'id'], 'at_job_retention'); }
        return $schema;
    }
}
