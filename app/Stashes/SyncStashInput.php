<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Broadcasts\BroadcastRepository;
use App\Downloads\DownloadPolicyEvaluator;
use App\Jobs\JobType;
use App\Jobs\JobDispatcher;
use App\Providers\ResolvedInput;
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
        private AssetAcquisitionPlanner $assetAcquisitions,
    ) {}

    public function execute(StashInputRecord $input, ?callable $onProgress = null, string $discoveryIntent = 'refresh'): StashInputSyncResult
    {
        $stashId = $input->stashId;
        $stashInputId = StashInputId::fromPrimaryKey($input->id);
        $stash = $this->stashes->find($stashId)
            ?? throw new RuntimeException('Stash input belongs to a stash that no longer exists.');

        try {
            $backfillMissing = $this->stashItems->hasMissingDiscoveryMetadata($stashInputId);
            $providerOptions = $input->options === null ? [] : $input->options->provider;

            if ($backfillMissing) {
                $providerOptions['skip_size_enrichment'] = true;
            }

            if ($discoveryIntent === 'complete') {
                $providerOptions['skip_enrichment'] = true;
            }

            $incremental = [];
            $commit = function (ResolvedInput $resolved, array $items, array $inputOptions) use ($stashId, $stashInputId, $input): DiscoveredItemCommitCounts {
                return $this->database->withinTransaction(fn(): DiscoveredItemCommitCounts => $this->committer->commit(
                    stashId: $stashId,
                    stashInputId: $stashInputId,
                    resolved: $resolved,
                    discoveredItems: $items,
                    inputOptions: $input->options,
                    declaredInputOptions: $inputOptions,
                ));
            };
            $dispatchDownloads = function (DiscoveredItemCommitCounts $counts) use ($stash, $stashId): void {
                if (! $this->downloadPolicy->allowsAutomaticDownload($stash->downloadPolicy)) {
                    return;
                }

                foreach ($counts->downloadableItemIds as $itemId) {
                    $this->jobDispatcher->dispatch('core.download', 'item', $itemId, $stashId->toString(), [
                        'item_id' => $itemId,
                        'stash_id' => $stashId->toString(),
                    ], 'background');
                }
            };

            $discovered = $this->discovery->execute([
                'source_uri' => $input->sourceUri,
                'source_title' => $input->title,
                'provider_options' => $providerOptions,
                'backfill_missing' => $backfillMissing,
                'discovery_intent' => $discoveryIntent,
            ], JobType::core('core.sync_input'), $onProgress, function (ResolvedInput $resolved, array $item, array $inputOptions) use (&$incremental, $commit, $dispatchDownloads, $onProgress): void {
                $counts = $commit($resolved, [$item], $inputOptions);
                $incremental[] = $counts;
                $dispatchDownloads($counts);
                $onProgress?->__invoke(sprintf('Discovered %d item(s)', count($incremental)), null);
            });

            $incremental[] = $commit($discovered->resolvedInput, $discovered->discoveredItems, $discovered->inputOptions);
            $counts = $this->combineCounts($incremental);
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
            $this->assetAcquisitions->dispatchMissing($stashId, $input);
        }

        $this->recordSuccess($input);

        return new StashInputSyncResult(
            stashId: $stashId->toString(),
            stashInputId: $stashInputId->toString(),
            itemsDiscovered: count($discovered->discoveredItems),
            itemsCreated: $counts->itemsCreated,
            stashItemsCreated: $counts->stashItemsCreated,
        );
    }

    /** @param list<array<string, mixed>> $discoveredItems */
    private function realignPositions(StashId $stashId, StashInputId $stashInputId, array $discoveredItems): void
    {
        $byProviderItemId = [];

        foreach ($this->stashItems->listForStash($stashId, stashInputId: $stashInputId) as $stashItem) {
            $byProviderItemId[$stashItem->item->providerItemId] = $stashItem;
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

    /** @param list<DiscoveredItemCommitCounts> $counts */
    private function combineCounts(array $counts): DiscoveredItemCommitCounts
    {
        return new DiscoveredItemCommitCounts(
            itemsCreated: array_sum(array_map(static fn(DiscoveredItemCommitCounts $count): int => $count->itemsCreated, $counts)),
            itemsReused: array_sum(array_map(static fn(DiscoveredItemCommitCounts $count): int => $count->itemsReused, $counts)),
            stashItemsCreated: array_sum(array_map(static fn(DiscoveredItemCommitCounts $count): int => $count->stashItemsCreated, $counts)),
            stashItemsReused: array_sum(array_map(static fn(DiscoveredItemCommitCounts $count): int => $count->stashItemsReused, $counts)),
        );
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
