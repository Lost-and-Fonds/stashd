<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Stashes\StashInputRecord;
use App\Vault\AssetRepository;
use App\Vault\AssetRecord;
use App\Vault\AssetState;
use App\Vault\ItemId;
use App\Vault\ItemRecord;

final readonly class BroadcastAssetRequirementEvaluator
{
    public function __construct(
        private AssetRepository $assets,
        private AssetAvailabilityRepository $availability,
        private ExternalInputPluginRegistry $inputs,
    ) {}

    /**
     * @param list<BroadcastAssetRequirement> $requirements
     * @param array<string, mixed> $settings
     */
    public function state(array $requirements, array $settings, ItemRecord $item, ?StashInputRecord $input): BroadcastAssetRequirementState
    {
        $pending = false;

        foreach ($requirements as $requirement) {
            if (! $requirement->required || ! $requirement->enabled($settings)) {
                continue;
            }

            $assets = $this->assets->listForItem(ItemId::fromPrimaryKey($item->id));
            $ready = array_filter(
                $assets,
                static fn(mixed $asset): bool => $asset instanceof AssetRecord
                    && $asset->state === AssetState::Ready
                    && $asset->path !== null
                    && $requirement->matches($asset),
            );

            if ($ready !== []) {
                continue;
            }

            if ($this->canAcquire($requirement, $item, $input)) {
                $pending = true;

                continue;
            }

            return BroadcastAssetRequirementState::PermanentlyUnavailable;
        }

        return $pending ? BroadcastAssetRequirementState::Pending : BroadcastAssetRequirementState::Ready;
    }

    private function canAcquire(BroadcastAssetRequirement $requirement, ItemRecord $item, ?StashInputRecord $input): bool
    {
        if ($input === null) {
            return false;
        }

        $definition = $this->inputs->definitionForProvider($item->providerKey);

        if ($definition === null) {
            return false;
        }

        foreach ($definition->assetCapabilities as $capability) {
            if (! $capability instanceof PluginAssetCapability
                || $capability->assetRole !== $requirement->assetRole
                || $requirement->kind !== null && $capability->kind !== $requirement->kind
                || ! $capability->enabled($input->options, $definition->options)) {
                continue;
            }

            if (! $this->availability->isPermanentlyUnavailable(ItemId::fromPrimaryKey($item->id), $capability, $definition->version)) {
                return true;
            }
        }

        return false;
    }
}
