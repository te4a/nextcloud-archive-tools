<?php

declare(strict_types=1);

namespace OCA\ArchiveTools\Archive;

final class MountArchiveSizeExceededException extends \RuntimeException {
    public function __construct() {
        parent::__construct('The archive exceeds the expanded size limit for connections.');
    }
}
