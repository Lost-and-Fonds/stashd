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

/**
 * Adapt local Tempest storage with constrained atomic publication renames.
 *
 * Rename operations require sibling entries inside the configured real root.
 * Files may replace files, while published directories cannot overwrite existing
 * entries. Other operations delegate to Tempest and do not gain rename atomicity.
 */
readonly class LocalArchiveStorage implements ArchiveStorage
{
    /**
     * Bind the tagged filesystem and its matching local root configuration.
     *
     * Both must refer to the same Vault; delegated I/O and rename resolution use
     * them independently.
     */
    public function __construct(
        #[Tag('vault')]
        private Storage $storage,
        private LocalStorageConfig $config,
    ) {}

    /**
     * Advertise atomic sibling directory rename and file replacement.
     *
     * Conditional writes are not supported; atomic rename remains subject to the
     * path and destination restrictions enforced by move().
     */
    public function supports(StorageCapability $capability): bool
    {
        return match ($capability) {
            StorageCapability::AtomicDirectoryRename, StorageCapability::AtomicFileReplace => true,
            default => false,
        };
    }

    /**
     * Atomically rename sibling entries within the real configured Vault root.
     *
     * Rejects symbolic-link entries, missing parents and cross-directory moves.
     * Directories never replace existing entries; files may replace files for
     * root inventory publication. Validation and rename failures propagate as
     * exceptions without a copy-and-delete fallback.
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
     * Delegate a byte write to Tempest storage and return this archival wrapper.
     *
     * This does not provide the atomic file replacement primitive used by move().
     */
    public function write(string $location, string $contents): static
    {
        $this->storage->write($location, $contents);

        return $this;
    }

    /**
     * Pass a caller-owned readable stream to Tempest storage for writing.
     *
     * The underlying provider consumes the stream; callers remain responsible
     * for closing it after this operation.
     */
    public function writeStream(string $location, mixed $contents): static
    {
        $this->storage->writeStream($location, $contents);

        return $this;
    }

    /**
     * Return complete file bytes from the delegated storage, propagating provider failures.
     */
    public function read(string $location): string
    {
        return $this->storage->read($location);
    }

    /**
     * Open a stored file through Tempest without buffering its full contents.
     *
     * The caller owns and must close the returned stream.
     */
    public function readStream(string $location): mixed
    {
        return $this->storage->readStream($location);
    }

    /**
     * Ask the underlying storage whether the relative location names a file.
     */
    public function fileExists(string $location): bool
    {
        return $this->storage->fileExists($location);
    }

    /**
     * Ask the underlying storage whether the relative location names a directory.
     */
    public function directoryExists(string $location): bool
    {
        return $this->storage->directoryExists($location);
    }

    /**
     * Check either file or directory existence through the underlying provider.
     */
    public function fileOrDirectoryExists(string $location): bool
    {
        return $this->storage->fileOrDirectoryExists($location);
    }

    /**
     * Remove a file through the delegated storage and return this wrapper.
     *
     * Deletion is destructive; this method does not protect committed history.
     */
    public function delete(string $location): static
    {
        $this->storage->delete($location);

        return $this;
    }

    /**
     * Recursively remove a directory through the delegated storage.
     *
     * The default empty location targets the storage root. This is destructive
     * and provides no archival-history guard or rollback.
     */
    public function deleteDirectory(?string $location = ''): static
    {
        $this->storage->deleteDirectory($location);

        return $this;
    }

    /**
     * Create a directory through Tempest and return this wrapper for chained operations.
     */
    public function createDirectory(?string $location = ''): static
    {
        $this->storage->createDirectory($location);

        return $this;
    }

    /**
     * Clear a directory through Tempest by deleting and recreating it.
     *
     * The default location is the storage root; no archival-history guard is applied.
     */
    public function cleanDirectory(?string $location = ''): static
    {
        $this->storage->cleanDirectory($location);

        return $this;
    }

    /**
     * Delegate an internal storage copy and return this wrapper.
     *
     * Byte verification is performed by the OCFL adapter, not by this operation.
     */
    public function copy(string $source, string $destination): static
    {
        $this->storage->copy($source, $destination);

        return $this;
    }

    /**
     * Return the underlying provider file size in bytes, propagating lookup failures.
     */
    public function fileSize(string $location): int
    {
        return $this->storage->fileSize($location);
    }

    /**
     * Return the provider modification timestamp for the requested stored file.
     */
    public function lastModified(string $location): int
    {
        return $this->storage->lastModified($location);
    }

    /**
     * Return the MIME type reported by Tempest storage for the requested location.
     */
    public function mimeType(string $location): string
    {
        return $this->storage->mimeType($location);
    }

    /**
     * Delegate a visibility change and return this wrapper without changing archival records.
     */
    public function setVisibility(string $location, string $visibility): static
    {
        $this->storage->setVisibility($location, $visibility);

        return $this;
    }

    /**
     * Return the provider visibility setting for the requested location.
     */
    public function visibility(string $location): string
    {
        return $this->storage->visibility($location);
    }

    /**
     * Request a public URL from the underlying storage provider.
     *
     * Availability depends on provider configuration; this does not verify bytes
     * or enforce the local Vault browser access policy.
     */
    public function publicUrl(string $location): string
    {
        return $this->storage->publicUrl($location);
    }

    /**
     * Request a provider URL expiring at the supplied time.
     *
     * Provider support and errors are delegated; no archival payload verification
     * is performed here.
     */
    public function temporaryUrl(string $location, DateTimeInterface $expiresAt): string
    {
        return $this->storage->temporaryUrl($location, $expiresAt);
    }

    /**
     * Return the underlying provider checksum for a stored file.
     *
     * Its algorithm is the provider default, not necessarily the OCFL digest algorithm.
     */
    public function checksum(string $location): string
    {
        return $this->storage->checksum($location);
    }

    /**
     * Return the provider directory listing, optionally including descendants.
     *
     * Locations remain storage-relative; the OCFL adapter translates these into
     * the entry-name and relative-file formats its contract expects.
     */
    public function list(string $location = '', bool $deep = false): DirectoryListing
    {
        return $this->storage->list($location, $deep);
    }

    /**
     * Validate a nonempty relative rename path and join it to the configured root.
     *
     * Rejects traversal, absolute paths, backslashes and NUL bytes with
     * InvalidArgumentException. Real-parent containment is checked by move().
     */
    private function resolvePath(string $path): string
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0") || in_array('..', explode('/', $path), true)) {
            throw new InvalidArgumentException('Invalid storage path');
        }

        return rtrim($this->config->path, '/') . '/' . $path;
    }
}
