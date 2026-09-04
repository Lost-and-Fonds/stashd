<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Broadcasts\BroadcastRepository;
use App\Downloads\DownloadPolicyEvaluator;
use App\Jobs\JobType;
use App\Jobs\JobDispatcher;
use RuntimeException;
use Tempest\Database\Database;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;
use Throwable;

/**
 * Re-checks one stash input against its upstream source and takes in whatever
 * is new.
 *
 * This is the whole operation, not a preview: discovery runs once and its
 * result is committed directly. Nothing here creates or renames anything at
 * stash level -- an input being synced already exists, so identity concerns
 * belong to CreateStashFromDiscovery, not here.
 */
final readonly class SyncStashInput
{
    public function __construct(
        private StashRepository $stashes,
        private StashInputRepository $stashInputs,
        private StashItemRepository $stashItems,
        private DiscoverStashInput $discovery,
        private DiscoveredItemCommitter $committer,
        private DownloadPolicyEvaluator $downloadPolicy,
        private JobDispatcher $jobDispatcher,
        private BroadcastRepository $broadcasts,
        private Database $database,
    ) {}

    public function execute(StashInputRecord $input): StashInputSyncResult
    {
        $stashId = $input->stashId;
        $stashInputId = StashInputId::fromPrimaryKey($input->id);
        $stash = $this->stashes->find($stashId)
            ?? throw new RuntimeException('Stash input belongs to a stash that no longer exists.');

        try {
            $discovered = $this->discovery->execute([
                'source_uri' => $input->sourceUri,
                'source_title' => $input->title,
                'provider_options' => $input->options === null ? [] : $input->options->provider,
            ], JobType::core('core.sync_input'));

            $counts = new DiscoveredItemCommitCounts();
            $committed = $this->database->withinTransaction(function () use (
                $stashId,
                $stashInputId,
                $discovered,
                $input,
                &$counts,
            ): void {
                $counts = $this->committer->commit(
                    stashId: $stashId,
                    stashInputId: $stashInputId,
                    resolved: $discovered->resolvedInput,
                    discoveredItems: $discovered->discoveredItems,
                    inputOptions: $input->options,
                    declaredInputOptions: $discovered->inputOptions,
                );
            });

            if (! $committed) {
                throw new RuntimeException('Failed to commit synced items.');
            }
        } catch (Throwable $throwable) {
            $this->recordFailure($input);

            throw $throwable;
        }

        if ($counts->stashItemsCreated > 0) {
            // Providers list newest-first, so a new item takes position 1 and
            // would tie with the previous holder -- and position is the default
            // sort for the stash list. Realigning to the current discovery order
            // keeps that list stable, and only runs on the rare changed sync.
            $this->realignPositions($stashId, $stashInputId, $discovered->discoveredItems);

            foreach ($this->broadcasts->listForStash($stashId) as $broadcast) {
                $this->jobDispatcher->dispatch('core.broadcast', 'broadcast', (string) $broadcast->id, $stashId->toString(), [
                    'broadcast_id' => (string) $broadcast->id,
                    'action' => 'rebuild',
                ], 'background');
            }
        }

        if ($this->downloadPolicy->allowsAutomaticDownload($stash->downloadPolicy)) {
            foreach ($counts->downloadableMediaItemIds as $mediaItemId) {
                $this->jobDispatcher->dispatch('core.download', 'media_item', $mediaItemId, $stashId->toString(), [
                    'media_item_id' => $mediaItemId,
                    'stash_id' => $stashId->toString(),
                ], 'background');
            }
        }

        $this->recordSuccess($input);

        return new StashInputSyncResult(
            stashId: $stashId->toString(),
            stashInputId: $stashInputId->toString(),
            itemsDiscovered: count($discovered->discoveredItems),
            mediaItemsCreated: $counts->mediaItemsCreated,
            stashItemsCreated: $counts->stashItemsCreated,
        );
    }

    /** @param list<array<string, mixed>> $discoveredItems */
    private function realignPositions(StashId $stashId, StashInputId $stashInputId, array $discoveredItems): void
    {
        $byProviderItemId = [];

        foreach ($this->stashItems->listForStash($stashId, stashInputId: $stashInputId) as $stashItem) {
            $byProviderItemId[$stashItem->mediaItem->providerItemId] = $stashItem;
        }

        foreach ($discoveredItems as $index => $item) {
            $rawItemId = $item['provider_item_id'] ?? null;
            $providerItemId = is_string($rawItemId) ? trim($rawItemId) : '';
            $stashItem = $byProviderItemId[$providerItemId] ?? null;

            if ($stashItem === null || $stashItem->position === $index + 1) {
                continue;
            }

            $stashItem->position = $index + 1;
            $stashItem->save();
        }
    }

    private function recordSuccess(StashInputRecord $input): void
    {
        $now = DateTime::now(Timezone::UTC);
        $input->lastCheckedAt = $now;
        $input->lastSuccessAt = $now;
        $input->consecutiveFailures = 0;
        $this->stashInputs->save($input);
    }

    private function recordFailure(StashInputRecord $input): void
    {
        $now = DateTime::now(Timezone::UTC);
        $input->lastCheckedAt = $now;
        $input->lastFailureAt = $now;
        $input->consecutiveFailures++;
        $this->stashInputs->save($input);
    }
}
