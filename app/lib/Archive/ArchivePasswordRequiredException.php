<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Archive;

final class ArchivePasswordRequiredException extends \RuntimeException {
    public function __construct(bool $provided = false) {
        parent::__construct($provided
            ? 'The archive password is incorrect or the archive is damaged.'
            : 'This archive requires a password.');
    }
}
