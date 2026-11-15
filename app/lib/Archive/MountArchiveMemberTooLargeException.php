<?php

declare(strict_types=1);

namespace OCA\ArchiveTools\Archive;

final class MountArchiveMemberTooLargeException extends \RuntimeException {
    public function __construct() {
        parent::__construct('A file in the archive exceeds the maximum size for connected files.');
    }
}
