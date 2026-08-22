<?php

declare(strict_types=1);

namespace App\System\Storage;

use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\DateTime\DateTime;

#[Table(name: 'storage_checks')]
final class StorageCheckRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    /** @param array<string, mixed>|null $details */
    public function __construct(
        public string $storageLocationId,
        public StorageCheckType $checkType,
        public StorageCheckState $state,
        public ?string $message = null,
        public ?array $details = null,
        public ?DateTime $createdAt = null,
        public ?DateTime $updatedAt = null,
    ) {}
}
