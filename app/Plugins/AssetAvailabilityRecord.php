<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Vault\AssetKind;
use App\Vault\AssetRole;
use App\Vault\ItemId;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\DateTime\DateTime;

#[Table(name: 'asset_availability_observations')]
final class AssetAvailabilityRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    public function __construct(
        public ItemId $itemId,
        public AssetRole $role,
        public AssetKind $kind,
        public string $providerVersion,
        public bool $permanent,
        public string $message,
        public DateTime $observedAt,
        public ?DateTime $createdAt = null,
        public ?DateTime $updatedAt = null,
    ) {}
}
