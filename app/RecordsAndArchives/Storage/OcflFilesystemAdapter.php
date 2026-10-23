<?php

namespace App\RecordsAndArchives\Storage;

use ArrayObject;
use Closure;
use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\Filesystem\Filesystem;
use RuntimeException;

/**
 * Bridge the OCFL filesystem contract to the configured archival storage.
 *
 * OCFL root paths are mapped to storage-relative paths. Absolute existing local
 * files may also supply acquisition bytes for copying and hashing. Verified
 * writes detect byte differences but do not roll back failed publication.
 */
readonly class OcflFilesystemAdapter implements Filesystem
{
    /**
     * Bind the provider and an optional shared record of failed storage observations.
     *
     * Inspection shares this record with its validation view so upstream validators
     * cannot hide provider failures by converting exceptions into validation issues.
     *
     * @param null|ArrayObject<int, ArchiveReadFailure> $readFailures
     */
    public function __construct(
        private ArchiveStorage $storage,
        private ?ArrayObject $readFailures = null,
    ) {}

    /**
     * Preserve provider failures as unavailable observations, never corruption evidence.
     *
     * The optional shared record survives third-party exception swallowing. Confirmed
     * absence and readable invalid bytes remain ordinary values for validation.
     *
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    private function observe(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (\Throwable $error) {
            $failure = new ArchiveReadFailure('Archive storage observation failed: ' . $error->getMessage(), previous: $error);
            if ($this->readFailures !== null) {
                $this->readFailures[0] ??= $failure;
            }
            throw $failure;
        }
    }

    /**
     * Strip leading slashes so OCFL root paths address the configured storage root.
     *
     * This helper is path translation, not traversal validation.
     */
    private static function normalizePath(string $path): string
    {
        return ltrim($path, '/');
    }

    /**
     * Reject source paths that traverse a symbolic link before copying or hashing.
     *
     * Throws RuntimeException on a linked component. It does not lock the path
     * against concurrent filesystem changes.
     */
    private static function validateSource(string $path): void
    {
        $component = $path;

        while ($component !== '.' && $component !== '/' && $component !== '') {
            if (is_link($component)) {
                throw new RuntimeException('Source file may not traverse a symbolic link');
            }

            $component = dirname($component);
        }
    }

    /**
     * Read complete stored bytes after translating the OCFL path to the storage root.
     */
    public function read(string $path): string
    {
        return $this->observe(fn (): string => $this->storage->read(self::normalizePath($path)));
    }

    /**
     * Open archived bytes while preserving provider failures for recovery callers.
     *
     * The caller owns the returned stream and must close it after verification.
     *
     * @return resource
     */
    public function readStream(string $path): mixed
    {
        return $this->observe(function () use ($path) {
            $stream = $this->storage->readStream(self::normalizePath($path));
            if (! is_resource($stream)) {
                throw new ArchiveReadFailure('Could not open archived content stream.');
            }
            return $stream;
        });
    }

    /**
     * Write bytes and independently read them back before returning.
     *
     * A mismatch raises RuntimeException after the write; existing content is
     * not restored automatically.
     */
    public function write(string $path, string $contents): void
    {
        $this->storage->write(self::normalizePath($path), $contents);
        if ($this->storage->read(self::normalizePath($path)) !== $contents) {
            throw new RuntimeException('Stored bytes differ from the requested write.');
        }
    }

    /**
     * Copy a local acquisition file or stored path and verify the adopted bytes.
     *
     * Hashes the source first, rejects linked source components and checks the
     * destination digest. Locally opened streams are closed on all outcomes.
     * Failure can leave destination bytes; publication cleanup belongs to OCFL.
     */
    public function copy(string $source, string $destination): void
    {
        self::validateSource($source);
        $expected = $this->digestFile($source, DigestAlgorithm::Sha512);

        if (str_starts_with($source, '/') && is_file($source)) {
            $stream = fopen($source, 'rb');

            if ($stream === false) {
                throw new RuntimeException('Could not open source file stream');
            }

            try {
                $this->storage->writeStream(self::normalizePath($destination), $stream);
                $this->verifyCopy($destination, $expected);
            } finally {
                fclose($stream);
            }

            return;
        }

        $this->storage->copy(self::normalizePath($source), self::normalizePath($destination));
        $this->verifyCopy($destination, $expected);
    }

    /**
     * Reread a copied destination and require the expected SHA-512 digest.
     *
     * Closes its verification stream. Incomplete reads and byte differences raise
     * RuntimeException before the version builder can publish the copy.
     */
    private function verifyCopy(string $destination, string $expected): void
    {
        // Read the adopted bytes through storage before VersionBuilder can publish them.
        $stream = $this->storage->readStream(self::normalizePath($destination));
        if (! is_resource($stream)) {
            throw new RuntimeException('Could not verify adopted bytes.');
        }
        try {
            $context = hash_init('sha512');
            hash_update_stream($context, $stream);
            if (! feof($stream)) {
                throw new ArchiveReadFailure('Could not read complete byte verification stream.');
            }
            if (! hash_equals($expected, hash_final($context))) {
                throw new RuntimeException('Adopted bytes differ from the accepted source.');
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * Delegate publication rename to the archival provider using relative paths.
     *
     * Atomicity and replacement restrictions come from that provider; this method
     * does not implement a copy-and-delete fallback.
     */
    public function move(string $source, string $destination): void
    {
        $this->storage->move(self::normalizePath($source), self::normalizePath($destination));
    }

    /**
     * Delete a stored file through the provider; this can destroy archival material.
     */
    public function delete(string $path): void
    {
        $this->storage->delete(self::normalizePath($path));
    }

    /**
     * Recursively delete the requested stored directory through the provider.
     *
     * This is destructive and must not be used to remove committed history casually.
     */
    public function deleteDirectory(string $path): void
    {
        $this->storage->deleteDirectory(self::normalizePath($path));
    }

    /**
     * Create the translated directory through the provider for OCFL staging or layout.
     */
    public function createDirectory(string $path): void
    {
        $this->storage->createDirectory(self::normalizePath($path));
    }

    /**
     * Check whether the translated OCFL path names a stored file.
     */
    public function fileExists(string $path): bool
    {
        return $this->observe(fn (): bool => $this->storage->fileExists(self::normalizePath($path)));
    }

    /**
     * Check whether the translated OCFL path names a stored directory.
     */
    public function directoryExists(string $path): bool
    {
        return $this->observe(fn (): bool => $this->storage->directoryExists(self::normalizePath($path)));
    }

    /**
     * Return immediate entry names rather than full storage paths.
     *
     * Includes files and directories from the provider listing; ordering follows
     * that provider.
     *
     * @return list<string>
     */
    public function listDirectory(string $path): array
    {
        $names = [];

        foreach ($this->observe(fn (): array => $this->storage->list(self::normalizePath($path))->toArray()) as $item) {
            $names[] = basename($item->path());
        }

        return $names;
    }

    /**
     * List file paths relative to the requested OCFL directory.
     *
     * Directories are omitted; traversal depth is delegated to the provider.
     *
     * @return list<string>
     */
    public function listFilesRecursively(string $path): array
    {
        $path = self::normalizePath($path);
        $prefix = $path === '' ? '' : $path . '/';

        $files = [];

        foreach ($this->observe(fn (): array => $this->storage->list($path, deep: true)->toArray()) as $item) {
            if (! $item->isFile()) {
                continue;
            }

            $files[] = substr($item->path(), strlen($prefix));
        }

        return $files;
    }

    /**
     * Hash an absolute existing local source or a file in archival storage.
     *
     * Rejects symbolic-link source components and requires a complete stream read.
     * Owns and closes the stream, propagating failures before returning a digest.
     */
    public function digestFile(
        string $path,
        DigestAlgorithm $algorithm,
    ): string {
        self::validateSource($path);

        if (str_starts_with($path, '/') && is_file($path)) {
            $stream = fopen($path, 'rb');
        } else {
            $stream = $this->observe(fn () => $this->storage->readStream(self::normalizePath($path)));
        }

        return $this->observe(function () use ($stream, $algorithm): string {
            if (! is_resource($stream)) {
                throw new ArchiveReadFailure('Could not open file stream');
            }

            try {
                $context = hash_init($algorithm->hashAlgorithm());

                hash_update_stream($context, $stream);
                if (! feof($stream)) {
                    throw new ArchiveReadFailure('Could not read complete byte verification stream.');
                }

                return hash_final($context);
            } finally {
                fclose($stream);
            }
        });
    }
}
