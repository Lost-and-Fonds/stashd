<?php

declare(strict_types=1);

namespace App\System\Storage;

use App\Support\PrefixedUlidGenerator;
use Tempest\Database\PrimaryKey;

use function Tempest\Database\query;

use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final class StorageCheckRepository
{
    public function __construct(
        private PrefixedUlidGenerator $ids,
    ) {}

    /** @param array<string, mixed>|null $details */
    public function record(
        string $storageLocationId,
        StorageCheckType $checkType,
        StorageCheckState $state,
        ?string $message = null,
        ?array $details = null,
    ): StorageCheckRecord {
        $id = $this->ids->generate('storagecheck')->toString();
        $record = new StorageCheckRecord(
            storageLocationId: $storageLocationId,
            checkType: $checkType,
            state: $state,
            message: $message,
            details: $details,
        );
        $record->id = new PrimaryKey($id);
        $now = DateTime::now(Timezone::UTC);
        $record->createdAt ??= $now;
        $record->updatedAt ??= $now;

        query(StorageCheckRecord::class)->insert($record)->execute();

        return $record;
    }
}
