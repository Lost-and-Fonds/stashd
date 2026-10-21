<?php

namespace App\RecordsAndArchives\Storage\Providers;

use App\RecordsAndArchives\Storage\ArchiveStorage;
use App\RecordsAndArchives\Storage\StorageCapability;
use DateTimeInterface;
use InvalidArgumentException;
use RuntimeException;
use Tempest\Container\Tag;
use Tempest\Storage\Config\LocalStorageConfig;
use Tempest\Storage\DirectoryListing;
use Tempest\Storage\Storage;

readonly class LocalArchiveStorage implements ArchiveStorage
{
    public function __construct(
        #[Tag('vault')]
        private Storage $storage,
        private LocalStorageConfig $config,
    ) {}

    public function supports(StorageCapability $capability): bool
    {
        return match ($capability) {
            StorageCapability::AtomicDirectoryRename, StorageCapability::AtomicFileReplace => true,
            default => false,
        };
    }

    /**
     * @inheritDoc
     */
    public function move(string $source, string $destination): static
    {
        // Validate and resolve storage-relative paths.
        $source = $this->resolvePath($source);
        $destination = $this->resolvePath($destination);

        // Resolve real directories, following any symlinks.
        $root = realpath($this->config->path);
        $sourceParent = realpath(dirname($source));
        $destinationParent = realpath(dirname($destination));

        // Make sure all the directories exist.
        if ($root === false || $sourceParent === false || $destinationParent === false) {
            throw new RuntimeException('Storage directory does not exist');
        }

        // OCFL currently publishes by renaming sibling entries.
        // Same actual directory means same filesystem.
        if ($sourceParent !== $destinationParent) {
            throw new RuntimeException('Atomic rename requires the same directory');
        }

        $parent = $sourceParent;

        if ($parent !== $root && ! str_starts_with($parent, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Path escapes the Vault root');
        }

        // Do not accept symbolic links as source or destination.
        if (is_link($source) || is_link($destination)) {
            throw new RuntimeException('Cannot rename symbolic links');
        }

        $sourceIsDirectory = is_dir($source);

        if (! $sourceIsDirectory && ! is_file($source)) {
            throw new RuntimeException('Rename source does not exist');
        }

        // Version directories must never replace existing content.
        if ($sourceIsDirectory && file_exists($destination)) {
            throw new RuntimeException('Destination directory already exists');
        }

        // File replacement is permitted for the OCFL root inventory.
        if (! $sourceIsDirectory && is_dir($destination)) {
            throw new RuntimeException('Cannot replace a directory with a file');
        }

        if (! @rename($source, $destination)) {
            throw new RuntimeException('Atomic rename failed');
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function write(string $location, string $contents): static
    {
        $this->storage->write($location, $contents);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function writeStream(string $location, mixed $contents): static
    {
        $this->storage->writeStream($location, $contents);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function read(string $location): string
    {
        return $this->storage->read($location);
    }

    /**
     * @inheritDoc
     */
    public function readStream(string $location): mixed
    {
        return $this->storage->readStream($location);
    }

    /**
     * @inheritDoc
     */
    public function fileExists(string $location): bool
    {
        return $this->storage->fileExists($location);
    }

    /**
     * @inheritDoc
     */
    public function directoryExists(string $location): bool
    {
        return $this->storage->directoryExists($location);
    }

    /**
     * @inheritDoc
     */
    public function fileOrDirectoryExists(string $location): bool
    {
        return $this->storage->fileOrDirectoryExists($location);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $location): static
    {
        $this->storage->delete($location);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function deleteDirectory(?string $location = ''): static
    {
        $this->storage->deleteDirectory($location);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function createDirectory(?string $location = ''): static
    {
        $this->storage->createDirectory($location);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function cleanDirectory(?string $location = ''): static
    {
        $this->storage->cleanDirectory($location);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function copy(string $source, string $destination): static
    {
        $this->storage->copy($source, $destination);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function fileSize(string $location): int
    {
        return $this->storage->fileSize($location);
    }

    /**
     * @inheritDoc
     */
    public function lastModified(string $location): int
    {
        return $this->storage->lastModified($location);
    }

    /**
     * @inheritDoc
     */
    public function mimeType(string $location): string
    {
        return $this->storage->mimeType($location);
    }

    /**
     * @inheritDoc
     */
    public function setVisibility(string $location, string $visibility): static
    {
        $this->storage->setVisibility($location, $visibility);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function visibility(string $location): string
    {
        return $this->storage->visibility($location);
    }

    /**
     * @inheritDoc
     */
    public function publicUrl(string $location): string
    {
        return $this->storage->publicUrl($location);
    }

    /**
     * @inheritDoc
     */
    public function temporaryUrl(string $location, DateTimeInterface $expiresAt): string
    {
        return $this->storage->temporaryUrl($location, $expiresAt);
    }

    /**
     * @inheritDoc
     */
    public function checksum(string $location): string
    {
        return $this->storage->checksum($location);
    }

    /**
     * @inheritDoc
     */
    public function list(string $location = '', bool $deep = false): DirectoryListing
    {
        return $this->storage->list($location, $deep);
    }

    private function resolvePath(string $path): string
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0") || in_array('..', explode('/', $path), true)) {
            throw new InvalidArgumentException('Invalid storage path');
        }

        return rtrim($this->config->path, '/') . '/' . $path;
    }
}
