<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\Stashes\StashId;
use App\System\State\StateTransitionService;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class BroadcastLifecycleResult
{
    /**
     * @param array<string, mixed>|null $plan
     * @param array<string, mixed>|null $publish
     * @param array<string, mixed>|null $verify
     * @param array<string, mixed>|null $prune
     * @param array<string, mixed>|null $trigger
     */
    public function __construct(
        public ?array $plan = null,
        public ?array $publish = null,
        public ?array $verify = null,
        public ?array $prune = null,
        public ?array $trigger = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'plan' => $this->plan,
            'publish' => $this->publish,
            'verify' => $this->verify,
            'prune' => $this->prune,
            'trigger' => $this->trigger,
        ], static fn ($value): bool => $value !== null);
    }
}

final readonly class BroadcastLifecycleService
{
    public function __construct(
        private BroadcastRepository $broadcasts,
        private BroadcastItemRepository $broadcastItems,
        private BroadcastContextFactory $contextFactory,
        private BroadcastPluginRegistry $plugins,
        private BroadcastTriggerService $triggers,
        private StateTransitionService $transitions,
        private BroadcastPathBuilder $paths,
        private PublishedResourceService $publications,
    ) {
    }

    public function plan(BroadcastId $broadcastId): BroadcastPlan
    {
        $broadcast = $this->broadcasts->find($broadcastId)
            ?? throw BroadcastException::withCode('broadcast_not_found', 'Broadcast not found.');

        $plan = $this->planOnly($broadcast);
        $broadcast->lastPlannedAt = DateTime::now(Timezone::UTC);
        $this->broadcasts->save($broadcast);

        return $plan;
    }

    /**
     * Storage-impact preview for a broadcast that doesn't exist yet, so the
     * create form can show what it'll actually do before committing to it.
     * Never persists anything -- BroadcastContextFactory only reads
     * $broadcast->stashId, so a broadcast that's never saved is enough to
     * reuse the real eligibility rule (publishableStashItems) instead of
     * re-deriving it here.
     *
     * Plugins may report generic derived work that is not known to be a byte
     * estimate yet; the lifecycle only displays the count.
     */
    public function preview(StashId $stashId, string $type, ?string $mediaKind): BroadcastCreationPreview
    {
        $draftBroadcast = new BroadcastRecord(
            stashId: $stashId,
            type: $type,
            name: '',
            slug: '',
            state: BroadcastState::Pending,
            settings: $mediaKind === null ? null : ['media_kind' => $mediaKind],
        );

        return $this->impactFor($draftBroadcast);
    }

    /**
     * Same storage-impact numbers as {@see preview()}, recomputed live for a
     * broadcast that already exists -- lets the broadcast card show current
     * impact ("N items, X already in the Vault, M pending transcode")
     * instead of only ever showing that snapshot at creation time.
     */
    public function impact(BroadcastId $broadcastId): BroadcastCreationPreview
    {
        $broadcast = $this->broadcasts->find($broadcastId)
            ?? throw BroadcastException::withCode('broadcast_not_found', 'Broadcast not found.');

        return $this->impactFor($broadcast);
    }

    public function impactFor(BroadcastRecord $broadcast, ?BroadcastContext $context = null): BroadcastCreationPreview
    {
        $context ??= $this->contextFactory->build($broadcast);
        $eligible = $this->contextFactory->publishableStashItems($context);

        $vaultSizeBytes = 0;

        foreach ($eligible as $stashItem) {
            $vaultOriginal = $context->vaultOriginals[(string) $stashItem->mediaItemId] ?? null;
            $vaultSizeBytes += $vaultOriginal->sizeBytes ?? 0;

        }

        $plugin = $this->resolvePlugin($broadcast->type)->plugin;
        $transcodeItemCount = $plugin instanceof BroadcastPluginPolicy
            ? $plugin->derivedWorkCount($context)
            : 0;

        return new BroadcastCreationPreview(
            eligibleItemCount: count($eligible),
            skippedItemCount: count($context->stashItems) - count($eligible),
            vaultSizeBytes: $vaultSizeBytes,
            hardlinkedItemCount: count($eligible) - $transcodeItemCount,
            transcodeItemCount: $transcodeItemCount,
        );
    }

    public function rebuild(BroadcastId $broadcastId, ?callable $onProgress = null): BroadcastLifecycleResult
    {
        $broadcast = $this->broadcasts->find($broadcastId)
            ?? throw \App\Broadcasts\BroadcastException::withCode('broadcast_not_found', 'Broadcast not found.');

        $this->transitionToProcessing($broadcast);

        if ($onProgress !== null) {
            $onProgress('Planning broadcast rebuild');
        }
        $plan = $this->planOnly($broadcast);
        $broadcast->lastPlannedAt = DateTime::now(Timezone::UTC);
        $this->broadcasts->save($broadcast);

        if ($onProgress !== null) {
            $onProgress('Publishing broadcast');
        }
        $publish = $this->publishOnly($broadcast, $plan);
        $broadcast->lastBuiltAt = DateTime::now(Timezone::UTC);
        $broadcast->lastError = null;
        $this->broadcasts->save($broadcast);

        $plugin = $this->resolvePlugin($broadcast->type)->plugin;
        $prune = ! ($plugin instanceof BroadcastPluginPolicy) || $plugin->prunesAfterPublish()
            ? $this->prune($broadcastId)
            : null;

        if ($onProgress !== null) {
            $onProgress('Verifying broadcast');
        }
        $verify = $this->verifyOnly($broadcast);
        $broadcast->lastVerifiedAt = DateTime::now(Timezone::UTC);
        $this->applyVerifyState($broadcast, $verify);
        $this->broadcasts->save($broadcast);

        $trigger = null;

        if ($verify->ok && $this->shouldAutoTrigger($broadcast) && ! ($plugin instanceof \App\Plugins\ExternalBroadcastPlugin)) {
            if ($onProgress !== null) {
                $onProgress('Triggering media server scan');
            }
            $trigger = $this->triggers->execute($broadcast, 'post_rebuild')->toArray();
        }

        return new BroadcastLifecycleResult(
            plan: $plan->toArray(),
            publish: $publish->toArray(),
            verify: $verify->toArray(),
            prune: $prune?->toArray(),
            trigger: $trigger,
        );
    }

    public function rebuildItem(BroadcastItemId $broadcastItemId, ?callable $onProgress = null): BroadcastLifecycleResult
    {
        $item = $this->broadcastItems->find($broadcastItemId)
            ?? throw BroadcastException::withCode('broadcast_item_not_found', 'Broadcast item not found.');
        $broadcast = $this->broadcasts->find($item->broadcastId)
            ?? throw BroadcastException::withCode('broadcast_not_found', 'Broadcast not found.');
        $plugin = $this->resolvePlugin($broadcast->type);

        if (! $plugin->plugin->supportsItemRebuild()) {
            throw BroadcastException::withCode(
                'broadcast_item_rebuild_unsupported',
                "The {$broadcast->type} broadcast format does not support item rebuilds.",
            );
        }

        $this->transitionToProcessing($broadcast);

        if ($onProgress !== null) {
            $onProgress('Planning broadcast item rebuild');
        }

        $plan = $this->planOnly($broadcast);
        $itemPlan = $this->planForItem($plan, (string) $item->stashItemId);

        if ($onProgress !== null) {
            $onProgress('Publishing broadcast item');
        }

        $publish = $this->publishOnly($broadcast, $itemPlan);
        $broadcast->lastPlannedAt = DateTime::now(Timezone::UTC);
        $broadcast->lastBuiltAt = DateTime::now(Timezone::UTC);
        $broadcast->lastError = null;

        if ($onProgress !== null) {
            $onProgress('Verifying broadcast');
        }

        // Publishing is deliberately limited to this item's files and
        // sidecars, but validation remains broadcast-wide: an item rebuild
        // must not accidentally mark an already-stale broadcast as ready.
        $verify = $this->verifyOnly($broadcast);
        $broadcast->lastVerifiedAt = DateTime::now(Timezone::UTC);
        $this->applyVerifyState($broadcast, $verify);
        $this->broadcasts->save($broadcast);

        $trigger = null;

        if ($verify->ok && $this->shouldAutoTrigger($broadcast) && ! ($plugin->plugin instanceof \App\Plugins\ExternalBroadcastPlugin)) {
            if ($onProgress !== null) {
                $onProgress('Triggering media server scan');
            }

            $trigger = $this->triggers->execute($broadcast, 'post_rebuild_item')->toArray();
        }

        return new BroadcastLifecycleResult(
            plan: $itemPlan->toArray(),
            publish: $publish->toArray(),
            verify: $verify->toArray(),
            trigger: $trigger,
        );
    }

    public function verify(BroadcastId $broadcastId): BroadcastVerifyResult
    {
        $broadcast = $this->broadcasts->find($broadcastId)
            ?? throw BroadcastException::withCode('broadcast_not_found', 'Broadcast not found.');

        $verify = $this->verifyOnly($broadcast);
        $broadcast->lastVerifiedAt = DateTime::now(Timezone::UTC);
        $this->applyVerifyState($broadcast, $verify);
        $this->broadcasts->save($broadcast);

        return $verify;
    }

    public function prune(BroadcastId $broadcastId): BroadcastPruneResult
    {
        $broadcast = $this->broadcasts->find($broadcastId)
            ?? throw BroadcastException::withCode('broadcast_not_found', 'Broadcast not found.');

        $context = $this->contextFactory->build($broadcast);
        $plugin = $this->resolvePlugin($context->broadcast->type);

        return $plugin->plugin->prune($context);
    }

    public function delete(BroadcastId $broadcastId): BroadcastDeleteResult
    {
        $broadcast = $this->broadcasts->find($broadcastId)
            ?? throw BroadcastException::withCode('broadcast_not_found', 'Broadcast not found.');
        $root = $this->paths->assertOwnsRoot($broadcast);
        $removedCount = 0;

        if (is_link($root)) {
            throw BroadcastException::withCode('broadcast_destination_conflict', 'Broadcast root cannot be a symlink.');
        }

        if (is_dir($root)) {
            /** @var SplFileInfo $entry */
            foreach (new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            ) as $entry) {
                $path = $entry->getPathname();
                $deleted = $entry->isDir() && ! $entry->isLink()
                    ? @rmdir($path)
                    : @unlink($path);

                if (! $deleted) {
                    throw BroadcastException::withCode('broadcast_delete_failed', 'Could not remove generated broadcast output.');
                }

                $removedCount++;
            }

            if (! @rmdir($root)) {
                throw BroadcastException::withCode('broadcast_delete_failed', 'Could not remove generated broadcast output.');
            }
        }

        $this->publications->revokeForBroadcast($broadcastId);

        $this->broadcasts->delete($broadcast);

        return new BroadcastDeleteResult($removedCount);
    }

    public function trigger(BroadcastId $broadcastId): BroadcastTriggerResult
    {
        $broadcast = $this->broadcasts->find($broadcastId)
            ?? throw BroadcastException::withCode('broadcast_not_found', 'Broadcast not found.');

        return $this->triggers->execute($broadcast, 'manual');
    }

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    public function invokePluginAction(BroadcastId $broadcastId, string $intent, array $payload = []): array
    {
        $broadcast = $this->broadcasts->find($broadcastId)
            ?? throw BroadcastException::withCode('broadcast_not_found', 'Broadcast not found.');
        $plugin = $this->resolvePlugin($broadcast->type)->plugin;

        if (! $plugin instanceof BroadcastPluginActions) {
            throw BroadcastException::withCode('broadcast_action_unsupported', 'Broadcast action is unsupported.');
        }

        return $plugin->invokeAction($broadcast, $intent, $payload);
    }

    private function planOnly(BroadcastRecord $broadcast): BroadcastPlan
    {
        $context = $this->contextFactory->build($broadcast);
        $plugin = $this->resolvePlugin($context->broadcast->type);

        return $plugin->plugin->plan($context);
    }

    private function publishOnly(BroadcastRecord $broadcast, ?BroadcastPlan $plan = null): \App\Broadcasts\BroadcastPublishResult
    {
        $context = $this->contextFactory->build($broadcast);
        $plugin = $this->resolvePlugin($context->broadcast->type);
        $plan ??= $plugin->plugin->plan($context);

        return $plugin->plugin->publish($context, $plan);
    }

    private function planForItem(BroadcastPlan $plan, string $stashItemId): BroadcastPlan
    {
        return new BroadcastPlan(
            broadcastId: $plan->broadcastId,
            broadcastRoot: $plan->broadcastRoot,
            files: array_values(array_filter(
                $plan->files,
                static fn (BroadcastPlannedFile $file): bool => $file->stashItemId === $stashItemId,
            )),
            sidecars: array_values(array_filter(
                $plan->sidecars,
                static fn (BroadcastPlannedSidecar $sidecar): bool => $sidecar->stashItemId === null || $sidecar->stashItemId === $stashItemId,
            )),
            skippedStashItemIds: array_values(array_filter(
                $plan->skippedStashItemIds,
                static fn (string $id): bool => $id === $stashItemId,
            )),
            estimatedCopyBytes: $plan->estimatedCopyBytes,
        );
    }

    private function verifyOnly(BroadcastRecord $broadcast): BroadcastVerifyResult
    {
        $context = $this->contextFactory->build($broadcast);
        $plugin = $this->resolvePlugin($context->broadcast->type);

        return $plugin->plugin->verify($context);
    }

    private function shouldAutoTrigger(\App\Broadcasts\BroadcastRecord $broadcast): bool
    {
        return (bool) ($broadcast->settings['auto_trigger_scan'] ?? false);
    }

    private function transitionToProcessing(\App\Broadcasts\BroadcastRecord $broadcast): void
    {
        if ($broadcast->state === BroadcastState::Processing) {
            return;
        }

        $this->transitions->transitionBroadcast($broadcast, BroadcastState::Processing);
    }

    private function resolvePlugin(string $type): DiscoveredPlugin
    {
        $plugin = $this->plugins->findByKey($type);

        if ($plugin === null) {
            throw new \InvalidArgumentException("Unknown broadcast type: {$type}");
        }

        return $plugin;
    }

    private function applyVerifyState(
        \App\Broadcasts\BroadcastRecord $broadcast,
        BroadcastVerifyResult $verify,
    ): void {
        if ($verify->ok) {
            if ($broadcast->state !== BroadcastState::Ready) {
                $this->transitions->transitionBroadcast($broadcast, BroadcastState::Ready);
            }

            return;
        }

        $broadcast->lastError = $this->staleReason(BroadcastId::fromPrimaryKey($broadcast->id));

        if ($broadcast->state !== BroadcastState::Stale) {
            $this->transitions->transitionBroadcast($broadcast, BroadcastState::Stale);
        }
    }

    /**
     * Prefers the specific reason already recorded on the stale/failed items
     * (e.g. a pending transcode) over the generic fallback, so a benign
     * in-progress state doesn't read as a hard failure. Falls back to the
     * generic code when items disagree or none carry a reason.
     */
    private function staleReason(BroadcastId $broadcastId): string
    {
        $reasons = [];

        foreach ($this->broadcastItems->listForBroadcast($broadcastId) as $item) {
            if ($item->lastError === null) {
                continue;
            }

            if (in_array($item->state, [BroadcastItemState::Stale, BroadcastItemState::Failed], true)) {
                $reasons[$item->lastError] = true;
            }
        }

        return count($reasons) === 1 ? array_key_first($reasons) : 'broadcast_verification_failed';
    }
}
