<?php

declare(strict_types=1);

namespace App\System\State;

use App\Broadcasts\BroadcastItemRecord;
use App\Broadcasts\BroadcastItemState;
use App\Broadcasts\BroadcastRecord;
use App\Broadcasts\BroadcastState;
use App\Stashes\StashInputRecord;
use App\Stashes\StashInputState;
use App\Stashes\StashItemRecord;
use App\Stashes\StashItemState;
use App\Stashes\StashRecord;
use App\Stashes\StashState;
use App\Vault\AssetRecord;
use App\Vault\AssetState;
use App\Vault\MediaItemRecord;
use App\Vault\MediaItemState;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class StateTransitionService
{
    public function transitionStash(StashRecord $record, StashState $next): StashRecord
    {
        return $this->apply($record, $record->state, $next, 'Stash');
    }

    public function transitionStashInput(StashInputRecord $record, StashInputState $next): StashInputRecord
    {
        return $this->apply($record, $record->state, $next, 'Stash input');
    }

    public function transitionStashItem(StashItemRecord $record, StashItemState $next): StashItemRecord
    {
        return $this->apply($record, $record->state, $next, 'Stash item');
    }

    public function transitionMediaItem(MediaItemRecord $record, MediaItemState $next): MediaItemRecord
    {
        return $this->apply($record, $record->state, $next, 'Media item');
    }

    public function transitionAsset(AssetRecord $record, AssetState $next): AssetRecord
    {
        return $this->apply($record, $record->state, $next, 'Asset');
    }

    public function transitionBroadcast(BroadcastRecord $record, BroadcastState $next): BroadcastRecord
    {
        return $this->apply($record, $record->state, $next, 'Broadcast');
    }

    public function transitionBroadcastItem(BroadcastItemRecord $record, BroadcastItemState $next): BroadcastItemRecord
    {
        return $this->apply($record, $record->state, $next, 'Broadcast item');
    }

    /**
     * @template TRecord of object
     *
     * @param  TRecord  $record
     * @param  object  $current
     * @param  object  $next
     * @return TRecord
     */
    private function apply(object $record, object $current, object $next, string $entity): object
    {
        if (! method_exists($current, 'canTransitionTo') || ! $current->canTransitionTo($next)) {
            throw InvalidStateTransition::forEntity(
                $entity,
                $this->stringifyState($current),
                $this->stringifyState($next),
            );
        }

        /** @var object{state: object, updatedAt?: ?DateTime} $mutable */
        $mutable = $record;
        /** @phpstan-ignore-next-line */
        $mutable->state = $next;

        if (property_exists($mutable, 'updatedAt')) {
            /** @phpstan-ignore-next-line */
            $mutable->updatedAt = DateTime::now(Timezone::UTC);
        }

        if (! method_exists($record, 'save')) {
            throw new \LogicException('Stateful record must provide save().');
        }

        $record->save();

        return $record;
    }

    private function stringifyState(object $state): string
    {
        return $state instanceof \BackedEnum ? (string) $state->value : $state::class;
    }
}
