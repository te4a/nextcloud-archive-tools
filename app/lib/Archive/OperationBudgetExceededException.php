<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Archive;

final class OperationBudgetExceededException extends \RuntimeException {
    public function __construct() { parent::__construct('The archive operation exceeded its time limit.'); }
}
