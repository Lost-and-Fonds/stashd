<?php

declare(strict_types=1);

namespace App\System\Health;

use App\System\Storage\StorageLocationKey;
use App\System\Storage\StorageLocationRepository;
use App\System\Storage\StorageLocationState;
use Tempest\Database\Config\DatabaseConfig;
use Tempest\Database\Config\SQLiteConfig;

final readonly class HealthReport
{
    public function __construct(
        public string $status,
        public bool $databaseWritable,
        public bool $storageReady,
        public bool $vaultBroadcastHardlink,
        /** @var list<array<string, mixed>> */
        public array $storageLocations,
        public string $version,
        public ?string $storageMessage = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'status' => $this->status,
            'version' => $this->version,
        ];
    }

    /** @return array<string, mixed> */
    public function toDetailedArray(): array
    {
        return [
            'status' => $this->status,
            'version' => $this->version,
            'database' => [
                'writable' => $this->databaseWritable,
            ],
            'storage' => [
                'ready' => $this->storageReady,
                'vault_broadcast_hardlink' => $this->vaultBroadcastHardlink,
                'message' => $this->storageMessage,
                'locations' => $this->storageLocations,
            ],
        ];
    }
}

final readonly class HealthService
{
    private const string VERSION = '0.1.0-dev';

    public function __construct(
        private DatabaseConfig $databaseConfig,
        private StorageLocationRepository $storageLocations,
    ) {
    }

    public function report(): HealthReport
    {
        $databaseWritable = $this->databaseIsWritable();
        $locations = $this->storageLocations->all();

        $storagePayload = [];
        $storageReady = true;
        $vaultBroadcastHardlink = true;
        $storageMessage = null;

        foreach ($locations as $location) {
            $storagePayload[] = [
                'key' => $location->key->value,
                'path' => $location->path,
                'state' => $location->state->value,
                'readable' => $location->readable,
                'writable' => $location->writable,
                'supports_hardlinks' => $location->supportsHardlinks,
                'last_error' => $location->lastError,
                'free_bytes' => $location->freeBytes,
                'total_bytes' => $location->totalBytes,
            ];

            if ($location->state !== StorageLocationState::Ready) {
                $storageReady = false;
            }

            if (in_array($location->key, [StorageLocationKey::Vault, StorageLocationKey::Broadcasts], true)) {
                $vaultBroadcastHardlink = $vaultBroadcastHardlink && $location->supportsHardlinks;
                if (! $location->supportsHardlinks && $location->lastError !== null) {
                    $storageMessage ??= $location->lastError;
                }
            }
        }

        if (! $vaultBroadcastHardlink) {
            $storageReady = false;
        }

        $status = ($databaseWritable && $storageReady) ? 'ok' : 'degraded';

        return new HealthReport(
            status: $status,
            databaseWritable: $databaseWritable,
            storageReady: $storageReady,
            vaultBroadcastHardlink: $vaultBroadcastHardlink,
            storageLocations: $storagePayload,
            version: self::VERSION,
            storageMessage: $storageMessage,
        );
    }

    private function databaseIsWritable(): bool
    {
        if (! $this->databaseConfig instanceof SQLiteConfig || $this->databaseConfig->path === ':memory:') {
            return true;
        }

        $directory = dirname($this->databaseConfig->path);

        return is_dir($directory) && is_writable($directory);
    }
}
