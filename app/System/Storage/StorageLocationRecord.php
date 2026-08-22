<?php

declare(strict_types=1);

namespace App\System\Storage;

use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\DateTime\DateTime;

#[Table(name: 'storage_locations')]
final class StorageLocationRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    public function __construct(
        public StorageLocationKey $key,
        public StorageLocationKey $role,
        public string $label,
        public string $path,
        public StorageLocationState $state,
        public bool $readable,
        public bool $writable,
        public ?int $freeBytes = null,
        public ?int $totalBytes = null,
        public ?string $filesystemId = null,
        public bool $supportsHardlinks = false,
        public bool $supportsSymlinks = false,
        public ?DateTime $lastCheckedAt = null,
        public ?string $lastError = null,
        public ?DateTime $createdAt = null,
        public ?DateTime $updatedAt = null,
    ) {}
}
