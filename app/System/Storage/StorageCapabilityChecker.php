<?php

declare(strict_types=1);

namespace App\System\Storage;

use App\Config\StashdConfig;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class StorageCapabilityChecker
{
    public function __construct(
        private StashdConfig $config,
        private StorageLocationRepository $locations,
        private StorageCheckRepository $checks,
        private FilesystemProbe $probe,
    ) {}

    public function checkAll(): FilesystemProbeResult
    {
        foreach ($this->config->storageRoots() as $key => $path) {
            $this->checkRoot(StorageLocationKey::from($key), $path);
        }

        return $this->checkVaultBroadcastHardlink();
    }

    public function checkRoot(StorageLocationKey $key, string $path): StorageLocationRecord
    {
        $writableProbe = $this->probe->probeWritable($path);
        $readable = is_dir($path) && is_readable($path);
        $writable = $writableProbe->ok;
        $state = StorageLocationState::Ready;
        $lastError = null;

        if (! $readable) {
            $state = is_dir($path) ? StorageLocationState::Unavailable : StorageLocationState::Missing;
            $lastError = $writableProbe->message;
        } elseif (! $writable) {
            $state = StorageLocationState::Unwritable;
            $lastError = $writableProbe->message;
        }

        $freeBytes = $writable ? @disk_free_space($path) : false;
        $totalBytes = $writable ? @disk_total_space($path) : false;
        $withinRootHardlink = $writable
            ? $this->probe->probeHardlinkWithinRoot($path)
            : FilesystemProbeResult::failed('Skipped hardlink probe because root is not writable.');

        if ($writable && is_numeric($freeBytes) && is_numeric($totalBytes) && $totalBytes > 0) {
            $freeRatio = $freeBytes / $totalBytes;

            if ($freeRatio < 0.05) {
                $state = StorageLocationState::LowSpace;
                $lastError = 'Less than 5% free space remaining.';
            }
        }

        $record = $this->locations->upsert(
            key: $key,
            role: $key,
            label: ucfirst($key->value),
            path: $path,
            state: $state,
            readable: $readable,
            writable: $writable,
            freeBytes: is_numeric($freeBytes) ? (int) $freeBytes : null,
            totalBytes: is_numeric($totalBytes) ? (int) $totalBytes : null,
            filesystemId: $writable ? $this->filesystemId($path) : null,
            supportsHardlinks: $withinRootHardlink->ok,
            supportsSymlinks: $writable && $this->probeSymlinks($path),
            lastError: $lastError ?? ($withinRootHardlink->ok ? null : $withinRootHardlink->message),
        );

        $this->recordCheck(
            location: $record,
            type: StorageCheckType::Writable,
            result: $writableProbe,
        );

        $this->recordCheck(
            location: $record,
            type: StorageCheckType::Hardlink,
            result: $withinRootHardlink,
        );

        return $record;
    }

    public function checkVaultBroadcastHardlink(): FilesystemProbeResult
    {
        $result = $this->probe->probeHardlinkCrossRoot(
            sourceRoot: $this->config->vaultPath(),
            targetRoot: $this->config->broadcastsPath(),
        );

        /** @var StorageLocationRecord|null $vault */
        $vault = StorageLocationRecord::select()
            ->where('key', StorageLocationKey::Vault)
            ->first();
        /** @var StorageLocationRecord|null $broadcasts */
        $broadcasts = StorageLocationRecord::select()
            ->where('key', StorageLocationKey::Broadcasts)
            ->first();

        if ($vault !== null) {
            $this->applyCrossRootHardlinkResult($vault, $result);
            $this->recordCheck($vault, StorageCheckType::Hardlink, $result, [
                'scope' => 'vault_to_broadcasts',
                'source_path' => $this->config->vaultPath(),
                'target_path' => $this->config->broadcastsPath(),
            ]);
        }

        if ($broadcasts !== null) {
            $this->applyCrossRootHardlinkResult($broadcasts, $result);
            $this->recordCheck($broadcasts, StorageCheckType::Hardlink, $result, [
                'scope' => 'vault_to_broadcasts',
                'source_path' => $this->config->vaultPath(),
                'target_path' => $this->config->broadcastsPath(),
            ]);
        }

        return $result;
    }

    private function applyCrossRootHardlinkResult(StorageLocationRecord $record, FilesystemProbeResult $result): void
    {
        $record->supportsHardlinks = $result->ok;

        if (! $result->ok) {
            $record->state = StorageLocationState::Failed;
            $record->lastError = $result->message;
        }

        $record->lastCheckedAt = DateTime::now(Timezone::UTC);
        $record->save();
    }

    /** @param array<string, mixed>|null $details */
    private function recordCheck(
        StorageLocationRecord $location,
        StorageCheckType $type,
        FilesystemProbeResult $result,
        ?array $details = null,
    ): void {
        $this->checks->record(
            storageLocationId: (string) $location->id,
            checkType: $type,
            state: $result->ok ? StorageCheckState::Ready : StorageCheckState::Failed,
            message: $result->message,
            details: array_merge($details ?? [], [
                'error_code' => $result->errorCode,
            ]),
        );
    }

    private function probeSymlinks(string $path): bool
    {
        $source = rtrim($path, '/') . '/.stashd-symlink-test';
        $target = rtrim($path, '/') . '/.stashd-symlink-target';

        if (@file_put_contents($source, 'x') === false) {
            return false;
        }

        if (is_file($target) || is_link($target)) {
            @unlink($target);
        }
        $linked = @symlink($source, $target);

        if (is_file($source) || is_link($source)) {
            @unlink($source);
        }

        if (is_file($target) || is_link($target)) {
            @unlink($target);
        }

        return $linked;
    }

    private function filesystemId(string $path): ?string
    {
        $root = realpath($path);

        if ($root === false) {
            return null;
        }

        $stat = stat($root);

        return $stat === false ? null : (string) $stat['dev'];
    }
}
