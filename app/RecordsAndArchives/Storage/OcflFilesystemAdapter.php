<?php

namespace App\RecordsAndArchives\Storage;

use League\Flysystem\StorageAttributes;
use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\Filesystem\Filesystem;
use RuntimeException;
use function Tempest\Mapper\map;

class OcflFilesystemAdapter implements Filesystem
{
    function __construct(
        private readonly StashdStorage $storage
    ) {}

    public function read(string $path): string
    {
        return $this->storage->read($path);
    }

    public function write(string $path, string $contents): void
    {
        $this->storage->write($path, $contents);
    }

    public function copy(string $source, string $destination): void
    {
        $this->storage->copy($source, $destination);
    }

    public function move(string $source, string $destination): void
    {
        $this->storage->move($source, $destination);
    }

    public function delete(string $path): void
    {
        $this->storage->delete($path);
    }

    public function deleteDirectory(string $path): void
    {
        $this->storage->deleteDirectory($path);
    }

    public function createDirectory(string $path): void
    {
        $this->storage->createDirectory($path);
    }

    public function fileExists(string $path): bool
    {
        return $this->storage->fileExists($path);
    }

    public function directoryExists(string $path): bool
    {
        return $this->storage->directoryExists($path);
    }

    /**
     * @inheritDoc
     */
    public function listDirectory(string $path): array
    {
        return $this->storage
            ->list($path)
            ->map(fn (StorageAttributes $item) => $item->path())
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    public function listFilesRecursively(string $path): array
    {
        return $this->storage
            ->list($path, deep: true)
            ->map(fn (StorageAttributes $item) => $item->path())
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    public function digestFile(
        string $path,
        DigestAlgorithm $algorithm,
    ): string {
        $stream = $this->storage->readStream($path);

        if (!is_resource($stream)) {
            throw new RuntimeException('Could not open file stream');
        }

        try {
            $context = hash_init($algorithm->hashAlgorithm());

            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }
}