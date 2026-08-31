<?php

declare(strict_types=1);

namespace App\System\Storage;

use App\Support\PrefixedUlidGenerator;
use Tempest\Database\PrimaryKey;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

use function Tempest\Database\query;

final class StorageLocationRepository
{
    public function __construct(
        private PrefixedUlidGenerator $ids,
    ) {}

    public function upsert(
        StorageLocationKey $key,
        StorageLocationKey $role,
        string $label,
        string $path,
        StorageLocationState $state,
        bool $readable,
        bool $writable,
        ?int $freeBytes,
        ?int $totalBytes,
        ?string $filesystemId,
        bool $supportsHardlinks,
        bool $supportsSymlinks,
        ?string $lastError,
    ): StorageLocationRecord {
        $now = DateTime::now(Timezone::UTC);
        $existing = StorageLocationRecord::select()
            ->where('key', $key)
            ->first();

        if ($existing !== null) {
            /** @var StorageLocationRecord $existing */
            $existing->role = $role;
            $existing->label = $label;
            $existing->path = $path;
            $existing->state = $state;
            $existing->readable = $readable;
            $existing->writable = $writable;
            $existing->freeBytes = $freeBytes;
            $existing->totalBytes = $totalBytes;
            $existing->filesystemId = $filesystemId;
            $existing->supportsHardlinks = $supportsHardlinks;
            $existing->supportsSymlinks = $supportsSymlinks;
            $existing->lastCheckedAt = $now;
            $existing->lastError = $lastError;
            $existing->save();

            return $existing;
        }

        $id = $this->ids->generate('storage')->toString();
        $record = new StorageLocationRecord(
            key: $key,
            role: $role,
            label: $label,
            path: $path,
            state: $state,
            readable: $readable,
            writable: $writable,
            freeBytes: $freeBytes,
            totalBytes: $totalBytes,
            filesystemId: $filesystemId,
            supportsHardlinks: $supportsHardlinks,
            supportsSymlinks: $supportsSymlinks,
            lastCheckedAt: $now,
            lastError: $lastError,
        );
        $record->id = new PrimaryKey($id);
        $record->createdAt ??= $now;
        $record->updatedAt ??= $now;

        query(StorageLocationRecord::class)->insert($record)->execute();

        return $record;
    }

    /** @return list<StorageLocationRecord> */
    public function all(): array
    {
        /** @var list<StorageLocationRecord> $records */
        $records = array_values(StorageLocationRecord::all());

        return $records;
    }

    public function findByKey(StorageLocationKey $key): ?StorageLocationRecord
    {
        /** @var StorageLocationRecord|null $record */
        $record = StorageLocationRecord::select()
            ->where('key', $key)
            ->first();

        return $record;
    }
}
