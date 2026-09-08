<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Support\PrefixedUlidGenerator;
use App\Vault\AssetKind;
use App\Vault\AssetRole;
use App\Vault\ItemId;
use Tempest\Database\PrimaryKey;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

use function Tempest\Database\query;

final class AssetAvailabilityRepository
{
    public function __construct(private PrefixedUlidGenerator $ids) {}

    public function find(ItemId $itemId, AssetRole $role, AssetKind $kind, string $providerVersion): ?AssetAvailabilityRecord
    {
        $record = AssetAvailabilityRecord::select()
            ->where('itemId', $itemId->toString())
            ->where('role', $role)
            ->where('kind', $kind)
            ->where('providerVersion', $providerVersion)
            ->first();

        return $record instanceof AssetAvailabilityRecord ? $record : null;
    }

    public function isPermanentlyUnavailable(ItemId $itemId, PluginAssetCapability $capability, string $providerVersion): bool
    {
        return $this->find($itemId, $capability->assetRole, $capability->kind, $providerVersion)?->permanent === true;
    }

    public function record(ItemId $itemId, PluginAssetCapability $capability, string $providerVersion, bool $permanent, string $message): AssetAvailabilityRecord
    {
        $record = $this->find($itemId, $capability->assetRole, $capability->kind, $providerVersion);
        $now = DateTime::now(Timezone::UTC);

        if ($record === null) {
            $record = new AssetAvailabilityRecord($itemId, $capability->assetRole, $capability->kind, $providerVersion, $permanent, $message, $now, $now, $now);
            $record->id = new PrimaryKey($this->ids->generate('asset-availability')->toString());
            query(AssetAvailabilityRecord::class)->insert($record)->execute();

            return $record;
        }

        $record->permanent = $permanent;
        $record->message = $message;
        $record->observedAt = $now;
        $record->updatedAt = $now;
        $record->save();

        return $record;
    }

    public function clear(ItemId $itemId, PluginAssetCapability $capability, string $providerVersion): void
    {
        $record = $this->find($itemId, $capability->assetRole, $capability->kind, $providerVersion);

        if ($record !== null) {
            $record->delete();
        }
    }
}
