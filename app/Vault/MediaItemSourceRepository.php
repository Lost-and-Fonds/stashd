<?php

declare(strict_types=1);

namespace App\Vault;

use App\Stashes\StashInputId;
use App\Support\PrefixedUlidGenerator;
use Tempest\Database\PrimaryKey;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

use function Tempest\Database\query;

final class MediaItemSourceRepository
{
    public function __construct(
        private PrefixedUlidGenerator $ids,
    ) {}

    public function create(
        MediaItemId $mediaItemId,
        string $providerKey,
        string $providerInputId,
        string $discoveredUri,
        ?StashInputId $stashInputId = null,
        ?int $position = null,
    ): MediaItemSourceRecord {
        $id = $this->ids->generate('source')->toString();
        $record = new MediaItemSourceRecord(
            mediaItemId: $mediaItemId,
            providerKey: $providerKey,
            providerInputId: $providerInputId,
            discoveredUri: $discoveredUri,
            discoveredAt: DateTime::now(Timezone::UTC),
            stashInputId: $stashInputId,
            position: $position,
        );
        $record->id = new PrimaryKey($id);

        query(MediaItemSourceRecord::class)->insert($record)->execute();

        return $record;
    }

    public function findForMediaItemAndInput(
        MediaItemId $mediaItemId,
        StashInputId $stashInputId,
    ): ?MediaItemSourceRecord {
        /** @var MediaItemSourceRecord|null $source */
        $source = MediaItemSourceRecord::select()
            ->where('mediaItemId', $mediaItemId->toString())
            ->where('stashInputId', $stashInputId->toString())
            ->first();

        return $source;
    }

    public function deleteForStashInput(StashInputId $stashInputId): void
    {
        query(MediaItemSourceRecord::class)
            ->delete()
            ->where('stashInputId', $stashInputId->toString())
            ->execute();
    }
}
