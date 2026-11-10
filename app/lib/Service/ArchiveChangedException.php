<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Service;

final class ArchiveChangedException extends \RuntimeException {
    public function __construct() {
        parent::__construct('The archive changed. Open it again before continuing.');
    }
}
