<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Jobs\JobDispatcher;
use App\Jobs\JobRepository;
use App\Jobs\JobType;
use App\Plugins\AssetAvailabilityRepository;
use App\Plugins\ExternalInputPluginRegistry;
use App\Plugins\PluginAssetCapability;
use App\System\Storage\VaultStorageAvailability;
use App\Vault\AssetRepository;
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
    ) {}

    public function dispatchMissing(StashId $stashId, StashInputRecord $input): int
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

            if ($item === null || $item->state !== ItemState::Ready) {
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

                $existing = $this->assets->findByItemAndRole($stashItem->itemId, $capability->assetRole);

                if (($existing !== null && in_array($existing->state, [AssetState::Ready, AssetState::Stale], true))
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
}
