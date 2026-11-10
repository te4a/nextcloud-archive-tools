<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Mount;

use OC\Files\Mount\MountPoint;
use OCP\Files\Mount\IMovableMount;

/**
 * The projection follows its parent folder's ID, including recipient aliases.
 * Core requires IMovableMount on children before it can relocate that parent.
 * Individual shared connections are changed through the authorized app API.
 */
final class ArchiveMountPoint extends MountPoint implements IMovableMount {
    public function moveMount(string $target): bool {
        return false;
    }

    public function removeMount(): bool {
        return false;
    }
}
