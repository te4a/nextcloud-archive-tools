<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Mount;

use Normalizer;
use OCA\ArchiveTools\Storage\ArchiveStorage;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Mount\IMovableMount;
use OCP\Files\Storage\IStorage;
use OCP\Files\Storage\IStorageFactory;
use Psr\Log\LoggerInterface;

/**
 * The projection follows its parent folder's ID, including recipient aliases.
 * Core requires IMovableMount on children before it can relocate that parent.
 * Individual shared connections are changed through the authorized app API.
 */
final class ArchiveMountPoint implements IMountPoint, IMovableMount {
    private const OPTIONS = [
        'readonly' => true,
        'enable_sharing' => false,
        'filesystem_check_changes' => 1,
        'previews' => true,
    ];

    private string $path;
    private string $directoryPath;
    private bool $storageAttempted = false;
    private ?IStorage $storage = null;
    private ?string $storageId = null;
    private ?int $numericStorageId = null;
    private int $rootId = -1;

    public function __construct(
        string $path,
        private readonly int $mountId,
        private readonly array $storageParameters,
        private readonly IStorageFactory $factory,
        private readonly LoggerInterface $logger,
    ) {
        $this->setMountPoint($path);
    }

    public function getMountPoint(): string {
        return $this->path;
    }

    public function setMountPoint($mountPoint): void {
        $path = (string)$mountPoint;
        $normalized = Normalizer::normalize($path);
        $this->directoryPath = self::normalizePath($normalized === false ? $path : $normalized);
        $this->path = $this->directoryPath === '/' ? '/' : $this->directoryPath . '/';
    }

    public function getStorage(): ?IStorage {
        if (!$this->storageAttempted) {
            $this->storageAttempted = true;
            // Wrappers may query the mount while the public factory is still applying them.
            $parameters = $this->storageParameters;
            $parameters['onConstructed'] = function (ArchiveStorage $storage): void {
                $this->storage = $storage;
            };
            try {
                $this->storage = $this->factory->getInstance($this, ArchiveStorage::class, $parameters);
            } catch (\Exception $error) {
                $this->storage = null;
                $this->storageId = null;
                $this->numericStorageId = null;
                $this->rootId = -1;
                $this->logger->warning('An archive storage could not be initialized.', [
                    'app' => 'archive_tools',
                    'connectionId' => $this->mountId,
                    'exceptionClass' => $error::class,
                ]);
            }
        }
        return $this->storage;
    }

    public function getStorageId(): ?string {
        return $this->storageId ??= $this->getStorage()?->getId();
    }

    public function getNumericStorageId(): int {
        if ($this->numericStorageId === null) {
            $storage = $this->getStorage();
            if ($storage === null) {
                return -1;
            }
            $this->numericStorageId = $storage->getCache()->getNumericStorageId();
        }
        return $this->numericStorageId;
    }

    public function getInternalPath($path): string {
        // Match the native mount lookup without changing member Unicode spelling.
        $path = self::normalizePath((string)$path);
        if ($path === $this->directoryPath) {
            return '';
        }
        return (string)substr($path, strlen($this->path));
    }

    private static function normalizePath(string $path): string {
        $parts = explode('/', str_replace('\\', '/', $path));
        return '/' . implode('/', array_filter($parts, static fn (string $part): bool => $part !== '' && $part !== '.'));
    }

    public function wrapStorage($wrapper): void {
        $storage = $this->getStorage();
        if ($storage !== null) {
            $this->storage = $wrapper($this->path, $storage, $this);
        }
    }

    public function getOption($name, $default): mixed {
        return self::OPTIONS[$name] ?? $default;
    }

    public function getOptions(): array {
        return self::OPTIONS;
    }

    public function getStorageRootId(): int {
        if ($this->rootId === -1) {
            $this->rootId = (int)($this->getStorage()?->getCache()->getId('') ?? -1);
        }
        return $this->rootId;
    }

    public function getMountId(): int {
        return $this->mountId;
    }

    public function getMountType(): string {
        return '';
    }

    public function getMountProvider(): string {
        return MountProvider::class;
    }

    public function moveMount(string $target): bool {
        return false;
    }

    public function removeMount(): bool {
        return false;
    }
}
