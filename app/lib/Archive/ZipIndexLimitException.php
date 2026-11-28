<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Archive;

final class ZipIndexLimitException extends \RuntimeException {
    public function __construct(string $message, public readonly bool $canRetryOnDemand = true) {
        parent::__construct($message);
    }
}
