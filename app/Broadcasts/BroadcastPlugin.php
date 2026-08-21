<?php

declare(strict_types=1);

namespace App\Broadcasts;

/**
 * Interface for broadcast plugins that generate views from stashed media.
 *
 * Each implementation represents a broadcast format and provides the full
 * lifecycle: planning, publishing, verifying,
 * and pruning broadcast output.
 */
interface BroadcastPlugin
{
    /**
     * Unique keys identifying broadcasts this plugin can produce.
     *
     * Each key maps to a broadcast format identifier. For example,
     * a Jellyfin plugin might return ['jellyfin_tv', 'jellyfin_movie'].
     *
     * @return list<string>
     */
    public function broadcastKeys(): array;

    /**
     * File kinds this plugin supports (video, audio, or both).
     *
     * @return list<FileKind>
     */
    public function supportedFileKinds(): array;

    /**
     * UI controls this plugin exposes for configuration.
     *
     * @return list<UiControl>
     */
    public function uiControls(): array;

    /**
     * Whether this format can safely regenerate one existing broadcast item.
     *
     * New formats must opt in explicitly; not every generated view has
     * item-scoped publication semantics.
     */
    public function supportsItemRebuild(): bool;

    /**
     * Plan a broadcast for the given stash items.
     */
    public function plan(BroadcastContext $context): BroadcastPlan;

    /**
     * Publish the planned broadcast.
     */
    public function publish(BroadcastContext $context, BroadcastPlan $plan): BroadcastPublishResult;

    /**
     * Verify broadcast integrity.
     */
    public function verify(BroadcastContext $context): BroadcastVerifyResult;

    /**
     * Prune stale broadcast output.
     */
    public function prune(BroadcastContext $context): BroadcastPruneResult;
}
