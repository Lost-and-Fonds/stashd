<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Broadcasts\BroadcastRepository;
use App\Http\Api\ApiJson;
use App\Downloads\DownloadPolicyEvaluator;
use App\Jobs\JobType;
use App\Jobs\JobDispatcher;
use RuntimeException;

/**
 * Commits a completed preflight into a stash input on an existing stash.
 *
 * Preflight only resolves identity and takes a cheap sample; this class re-runs
 * full discovery (the best available plugin capability) rather than replaying
 * preflight's frozen sample, then persists the
 * stash input, media items, sources, and stash items, deduplicating against
 * whatever the stash (and the wider Vault) already has.
 */
final readonly class CreateStashFromDiscovery
{
    public function __construct(
        private StashRepository $stashes,
        private StashInputRepository $stashInputs,
        private DiscoveredItemCommitter $committer,
        private JobDispatcher $jobDispatcher,
        private DownloadPolicyEvaluator $downloadPolicy,
        private BroadcastRepository $broadcasts,
    ) {}

    /**
     * Persists a resolved Input and its discovered items. The caller may wrap
     * this in a larger transaction when creating the Stash itself.
     *
     * @param array<string, mixed> $options
     */
    public function persistDiscoveredInput(StashRecord $stash, InputPreflightResult $discovered, array $options = []): StashInputCommitResult
    {

        $resolved = $discovered->resolvedInput;
        $discoveredItems = $discovered->discoveredItems;
        $declaredInputOptions = $discovered->inputOptions;
        $inputOptions = StashInputOptions::fromArray($options);

        $stashId = StashId::fromPrimaryKey($stash->id);
        $inputType = StashInputTypeMapper::fromProviderInputType($resolved->inputType);

        $syncMode = SyncMode::tryFrom(ApiJson::string($options['sync_mode'] ?? null, SyncMode::Automatic->value)) ?? SyncMode::Automatic;

        $stashInput = null;
        $counts = new DiscoveredItemCommitCounts();

        $isFirstInput = $this->stashInputs->listForStash($stashId) === [];
        $stashInput = $this->stashInputs->findByStashAndProviderInput(
            $stashId,
            $resolved->providerKey,
            $resolved->providerInputId,
        ) ?? $this->stashInputs->create(
            stashId: $stashId,
            providerKey: $resolved->providerKey,
            inputType: $inputType,
            sourceUri: $resolved->sourceUri->toString(),
            providerInputId: $resolved->providerInputId,
            title: $resolved->title,
            syncMode: $syncMode,
            options: $inputOptions,
        );

        if ($stashInput->title !== $resolved->title || $stashInput->sourceUri !== $resolved->sourceUri->toString() || $stashInput->options?->toArray() !== $inputOptions?->toArray()) {
            $stashInput->title = $resolved->title;
            $stashInput->sourceUri = $resolved->sourceUri->toString();
            $stashInput->options = $inputOptions;
            $this->stashInputs->save($stashInput);
        }

        if ($stash->iconUri === null && $resolved->sourceAvatarUri !== null) {
            $this->stashes->update($stash, iconUri: $resolved->sourceAvatarUri->toString());
        }

        if ($isFirstInput && $stash->name === 'New Stash' && $resolved->sourceTitle !== null) {
            $this->stashes->update($stash, name: $resolved->sourceTitle);
        }

        $counts = $this->committer->commit(
            stashId: $stashId,
            stashInputId: StashInputId::fromPrimaryKey($stashInput->id),
            resolved: $resolved,
            discoveredItems: $discoveredItems,
            inputOptions: $inputOptions,
            declaredInputOptions: $declaredInputOptions,
        );

        return new StashInputCommitResult(
            stashId: (string) $stash->id,
            stashInputId: (string) $stashInput->id,
            mediaItemsCreated: $counts->mediaItemsCreated,
            mediaItemsReused: $counts->mediaItemsReused,
            stashItemsCreated: $counts->stashItemsCreated,
            stashItemsReused: $counts->stashItemsReused,
            downloadableMediaItemIds: $counts->downloadableMediaItemIds,
        );
    }

    public function dispatchFollowups(StashRecord $stash, StashInputCommitResult $result): void
    {
        $stashId = StashId::fromPrimaryKey($stash->id);

        if ($this->downloadPolicy->allowsAutomaticDownload($stash->downloadPolicy)) {
            foreach ($result->downloadableMediaItemIds as $mediaItemId) {
                $this->jobDispatcher->dispatch('core.download', 'media_item', $mediaItemId, $stashId->toString(), [
                    'media_item_id' => $mediaItemId,
                    'stash_id' => $stashId->toString(),
                ], 'background');
            }
        }

        foreach ($this->broadcasts->listForStash($stashId) as $broadcast) {
            $this->jobDispatcher->dispatch('core.broadcast', 'broadcast', (string) $broadcast->id, $stashId->toString(), [
                'broadcast_id' => (string) $broadcast->id,
                'action' => 'rebuild',
            ], 'background');
        }
    }

}
