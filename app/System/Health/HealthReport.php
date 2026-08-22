<?php

declare(strict_types=1);

namespace App\System\Health;

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
    ) {}

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
