<?php

declare(strict_types=1);

namespace App\System\Health;

use App\System\Storage\StorageLocationKey;
use App\System\Storage\StorageLocationRepository;
use App\System\Storage\StorageLocationState;

final readonly class HealthService
{
    private const string VERSION = '0.1.0-dev';

    public function __construct(private StorageLocationRepository $storageLocations) {}

    public function report(): HealthReport
    {
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

        $status = $storageReady ? 'ok' : 'degraded';

        return new HealthReport(
            status: $status,
            databaseWritable: true,
            storageReady: $storageReady,
            vaultBroadcastHardlink: $vaultBroadcastHardlink,
            storageLocations: $storagePayload,
            version: self::VERSION,
            storageMessage: $storageMessage,
        );
    }

}
