<?php

namespace App\RecordsAndArchives\Storage;

use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\Filesystem\Filesystem;
use RuntimeException;

readonly class OcflFilesystemAdapter implements Filesystem
{
    public function __construct(
        private ArchiveStorage $storage,
    ) {}

    private static function normalizePath(string $path): string
    {
        return ltrim($path, '/');
    }

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

    public function read(string $path): string
    {
        return $this->storage->read(self::normalizePath($path));
    }

    public function write(string $path, string $contents): void
    {
        $this->storage->write(self::normalizePath($path), $contents);
        if ($this->storage->read(self::normalizePath($path)) !== $contents) {
            throw new RuntimeException('Stored bytes differ from the requested write.');
        }
    }

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
                throw new RuntimeException('Could not read complete byte verification stream.');
            }
            if (! hash_equals($expected, hash_final($context))) {
                throw new RuntimeException('Adopted bytes differ from the accepted source.');
            }
        } finally {
            fclose($stream);
        }
    }

    public function move(string $source, string $destination): void
    {
        $this->storage->move(self::normalizePath($source), self::normalizePath($destination));
    }

    public function delete(string $path): void
    {
        $this->storage->delete(self::normalizePath($path));
    }

    public function deleteDirectory(string $path): void
    {
        $this->storage->deleteDirectory(self::normalizePath($path));
    }

    public function createDirectory(string $path): void
    {
        $this->storage->createDirectory(self::normalizePath($path));
    }

    public function fileExists(string $path): bool
    {
        return $this->storage->fileExists(self::normalizePath($path));
    }

    public function directoryExists(string $path): bool
    {
        return $this->storage->directoryExists(self::normalizePath($path));
    }

    /**
     * @inheritDoc
     */
    public function listDirectory(string $path): array
    {
        $names = [];

        foreach ($this->storage->list(self::normalizePath($path))->toArray() as $item) {
            $names[] = basename($item->path());
        }

        return $names;
    }

    /**
     * @inheritDoc
     */
    public function listFilesRecursively(string $path): array
    {
        $path = self::normalizePath($path);
        $prefix = $path === '' ? '' : $path . '/';

        $files = [];

        foreach ($this->storage->list($path, deep: true)->toArray() as $item) {
            if (! $item->isFile()) {
                continue;
            }

            $files[] = substr($item->path(), strlen($prefix));
        }

        return $files;
    }

    /**
     * @inheritDoc
     */
    public function digestFile(
        string $path,
        DigestAlgorithm $algorithm,
    ): string {
        self::validateSource($path);

        if (str_starts_with($path, '/') && is_file($path)) {
            $stream = fopen($path, 'rb');
        } else {
            $stream = $this->storage->readStream(self::normalizePath($path));
        }

        if (! is_resource($stream)) {
            throw new RuntimeException('Could not open file stream');
        }

        try {
            $context = hash_init($algorithm->hashAlgorithm());

            hash_update_stream($context, $stream);
            if (! feof($stream)) {
                throw new RuntimeException('Could not read complete byte verification stream.');
            }

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }
}
