<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Broadcasts\BroadcastRecord;
use App\Jobs\JobDispatcher;
use App\Jobs\JobRepository;
use App\Jobs\JobType;
use App\Plugins\AssetAvailabilityRepository;
use App\Plugins\BroadcastAssetRequirement;
use App\Plugins\ExternalBroadcastPluginRegistry;
use App\Plugins\ExternalInputPluginRegistry;
use App\Plugins\PluginAssetCapability;
use App\System\Storage\VaultStorageAvailability;
use App\Vault\AssetRepository;
use App\Vault\AssetRecord;
use App\Vault\AssetState;
use App\Vault\ItemState;

final readonly class AssetAcquisitionPlanner
{
    public function __construct(
        private StashItemRepository $stashItems,
        private AssetRepository $assets,
        private ExternalInputPluginRegistry $plugins,
        private AssetAvailabilityRepository $availability,
        private JobRepository $jobs,
        private JobDispatcher $dispatch,
        private VaultStorageAvailability $storage,
        private ExternalBroadcastPluginRegistry $broadcasts,
        private StashInputRepository $inputs,
    ) {}

    /** @param list<BroadcastAssetRequirement>|null $requirements */
    public function dispatchMissing(StashId $stashId, StashInputRecord $input, ?array $requirements = null): int
    {
        if ($this->storage->isUnavailable()) {
            return 0;
        }

        $definition = $this->plugins->definitionForProvider($input->providerKey);

        if ($definition === null || $definition->assetCapabilities === []) {
            return 0;
        }

        $activeAcquisitions = $this->jobs->pendingOrProcessingEntityIds(JobType::core('core.acquire_assets'), 'item');
        $activeDownloads = $this->jobs->pendingOrProcessingEntityIds(JobType::core('core.download'), 'item');
        $dispatched = 0;

        foreach ($this->stashItems->listForStash($stashId, includeIgnored: false, stashInputId: StashInputId::fromPrimaryKey($input->id)) as $stashItem) {
            $item = $stashItem->item;

            if ($item === null || ! in_array($item->state, [
                ItemState::Discovered,
                ItemState::MetadataReady,
                ItemState::Ready,
                ItemState::Missing,
            ], true)) {
                continue;
            }

            $itemId = (string) $item->id;

            if (isset($activeAcquisitions[$itemId]) || isset($activeDownloads[$itemId])) {
                continue;
            }

            $roles = [];

            foreach ($definition->assetCapabilities as $capability) {
                if (! $capability instanceof PluginAssetCapability || ! $capability->enabled($input->options, $definition->options)) {
                    continue;
                }

                if ($requirements !== null && ! $this->matchesRequirement($capability, $requirements)) {
                    continue;
                }

                $existing = array_filter(
                    $this->assets->listForItem($stashItem->itemId),
                    static fn(mixed $asset): bool => $asset instanceof AssetRecord
                        && $asset->role === $capability->assetRole
                        && $asset->kind === $capability->kind,
                );

                if (array_filter($existing, static fn(AssetRecord $asset): bool => in_array($asset->state, [AssetState::Ready, AssetState::Stale], true)) !== []
                    || $this->availability->isPermanentlyUnavailable($stashItem->itemId, $capability, $definition->version)) {
                    continue;
                }

                $roles[] = $capability->role;
            }

            if ($roles === []) {
                continue;
            }

            $this->dispatch->dispatch(
                'core.acquire_assets',
                entityType: 'item',
                entityId: $itemId,
                stashId: $stashId->toString(),
                payload: [
                    'item_id' => $itemId,
                    'roles' => array_values(array_unique($roles)),
                    'provider_options' => $input->options?->provider ?? [],
                ],
                workload: 'background',
            );
            $activeAcquisitions[$itemId] = true;
            $dispatched++;
        }

        return $dispatched;
    }

    public function dispatchMissingForBroadcast(BroadcastRecord $broadcast): int
    {
        $definition = $this->broadcasts->findByLogicalKey($broadcast->type);

        if ($definition === null) {
            return 0;
        }

        $requirements = array_values(array_filter(
            $definition->assetRequirements,
            static fn(mixed $requirement): bool => $requirement instanceof BroadcastAssetRequirement
                && $requirement->enabled($broadcast->settings ?? []),
        ));

        if ($requirements === []) {
            return 0;
        }

        $dispatched = 0;

        foreach ($this->inputs->listForStash($broadcast->stashId) as $input) {
            $dispatched += $this->dispatchMissing($broadcast->stashId, $input, $requirements);
        }

        return $dispatched;
    }

    /** @param list<BroadcastAssetRequirement> $requirements */
    private function matchesRequirement(PluginAssetCapability $capability, array $requirements): bool
    {
        foreach ($requirements as $requirement) {
            if ($requirement->assetRole === $capability->assetRole
                && ($requirement->kind === null || $requirement->kind === $capability->kind)) {
                return true;
            }
        }

        return false;
    }
}
