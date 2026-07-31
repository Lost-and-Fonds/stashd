<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Support\PrefixedUlidGenerator;
use Tempest\Database\Builder\QueryBuilders\WhereGroupBuilder;
use Tempest\Database\Builder\WhereOperator;
use Tempest\Database\Direction;
use Tempest\Database\PrimaryKey;

use function Tempest\Database\query;

use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final class StashInputRepository
{
    public function __construct(
        private PrefixedUlidGenerator $ids,
    ) {
    }

    public function create(
        StashId $stashId,
        string $providerKey,
        StashInputType $inputType,
        string $sourceUri,
        string $providerInputId,
        ?string $title = null,
        ?SyncMode $syncMode = null,
        ?StashInputOptions $options = null,
    ): StashInputRecord {
        $id = $this->ids->generate('input')->toString();
        $record = new StashInputRecord(
            stashId: $stashId,
            providerKey: $providerKey,
            inputType: $inputType,
            sourceUri: $sourceUri,
            providerInputId: $providerInputId,
            state: StashInputState::Ready,
            title: $title,
            syncMode: $syncMode,
            options: $options,
        );
        $record->id = new PrimaryKey($id);
        $now = DateTime::now(Timezone::UTC);
        $record->createdAt ??= $now;
        $record->updatedAt ??= $now;

        query(StashInputRecord::class)->insert($record)->execute();

        return $record;
    }

    public function find(StashInputId $id): ?StashInputRecord
    {
        return StashInputRecord::findById($id->toPrimaryKey());
    }

    public function findByStashAndProviderInput(StashId $stashId, string $providerKey, string $providerInputId): ?StashInputRecord
    {
        $input = StashInputRecord::select()
            ->where('stashId', $stashId->toString())
            ->where('providerKey', $providerKey)
            ->where('providerInputId', $providerInputId)
            ->first();

        return $input instanceof StashInputRecord ? $input : null;
    }

    public function save(StashInputRecord $record): StashInputRecord
    {
        $record->updatedAt = DateTime::now(Timezone::UTC);
        $record->save();

        return $record;
    }

    public function updateOptions(StashInputRecord $record, ?StashInputOptions $options): StashInputRecord
    {
        $record->options = $options;

        return $this->save($record);
    }

    /** @return list<StashInputRecord> */
    public function listDueForAutomaticSync(DateTime $now): array
    {
        return StashInputRecord::select()
            ->where('state', StashInputState::Ready)
            ->where('syncMode', SyncMode::Automatic)
            ->andWhereGroup(fn (WhereGroupBuilder $group) => $group
                ->whereNull('nextCheckAt')
                ->orWhere('nextCheckAt', $now, WhereOperator::LESS_THAN_OR_EQUAL))
            ->orderBy('nextCheckAt', Direction::ASC)
            ->all();
    }

    /** @return list<StashInputRecord> */
    public function listForStash(StashId $stashId): array
    {
        return StashInputRecord::select()
            ->where('stashId', $stashId->toString())
            ->orderBy('createdAt', Direction::ASC)
            ->all();
    }
}
