<?php

declare(strict_types=1);

namespace App\Vault;

use App\Stashes\StashInputId;
use App\Support\PrefixedUlidGenerator;
use Tempest\Database\PrimaryKey;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

use function Tempest\Database\query;

final class ItemSourceRepository
{
    public function __construct(
        private PrefixedUlidGenerator $ids,
    ) {}

    public function create(
        ItemId $itemId,
        string $providerKey,
        string $providerInputId,
        string $discoveredUri,
        ?StashInputId $stashInputId = null,
        ?int $position = null,
    ): ItemSourceRecord {
        $id = $this->ids->generate('source')->toString();
        $record = new ItemSourceRecord(
            itemId: $itemId,
            providerKey: $providerKey,
            providerInputId: $providerInputId,
            discoveredUri: $discoveredUri,
            discoveredAt: DateTime::now(Timezone::UTC),
            stashInputId: $stashInputId,
            position: $position,
        );
        $record->id = new PrimaryKey($id);

        query(ItemSourceRecord::class)->insert($record)->execute();

        return $record;
    }

    public function findForItemAndInput(
        ItemId $itemId,
        StashInputId $stashInputId,
    ): ?ItemSourceRecord {
        /** @var ItemSourceRecord|null $source */
        $source = ItemSourceRecord::select()
            ->where('itemId', $itemId->toString())
            ->where('stashInputId', $stashInputId->toString())
            ->first();

        return $source;
    }

    public function deleteForStashInput(StashInputId $stashInputId): void
    {
        query(ItemSourceRecord::class)
            ->delete()
            ->where('stashInputId', $stashInputId->toString())
            ->execute();
    }
}
