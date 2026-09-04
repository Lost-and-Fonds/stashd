<?php

declare(strict_types=1);

namespace App\Config;

final readonly class StashdConfig
{
    public function __construct(
        public string $dataPath,
        public string $mediaPath,
        public string $publicUrl,
        public string $logFormat,
        public int $puid,
        public int $pgid,
        public string $umask,
        public string $httpPort,
        /** @var array<string, array{min_workers: int, max_workers: int}> */
        public array $workers = [],
    ) {}

    public function vaultPath(): string
    {
        return rtrim($this->mediaPath, '/') . '/vault';
    }

    public function broadcastsPath(): string
    {
        return rtrim($this->mediaPath, '/') . '/broadcasts';
    }

    public function tempPath(): string
    {
        return rtrim($this->mediaPath, '/') . '/temp';
    }

    public function cachePath(): string
    {
        return rtrim($this->mediaPath, '/') . '/cache';
    }

    public function backupsPath(): string
    {
        return rtrim($this->dataPath, '/') . '/backups';
    }

    /** @return array<string, string> */
    public function storageRoots(): array
    {
        return [
            'data' => $this->dataPath,
            'vault' => $this->vaultPath(),
            'broadcasts' => $this->broadcastsPath(),
            'temp' => $this->tempPath(),
            'cache' => $this->cachePath(),
        ];
    }
}
