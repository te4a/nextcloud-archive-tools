<?php

declare(strict_types=1);

namespace OCA\ArchiveTools\Archive;

/** Size policy shared by the readers used for connections. */
final class MountArchiveLimits {
    public const MIN_EXPANDED_BYTES = 1048576;
    public const MAX_MEMBER_BYTES = 4294967296;

    public function __construct(public readonly int $expandedBytes) {
        if ($expandedBytes < 1) {
            throw new \InvalidArgumentException('Invalid archive connection size limit.');
        }
    }

    public function checkFileSize(int $size, int $previousBytes): void {
        if ($size > self::MAX_MEMBER_BYTES) {
            throw new MountArchiveMemberTooLargeException();
        }
        if ($size > $this->expandedBytes - $previousBytes) {
            throw new MountArchiveSizeExceededException();
        }
    }
}
