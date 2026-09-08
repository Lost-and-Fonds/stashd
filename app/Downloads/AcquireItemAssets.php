<?php

declare(strict_types=1);

namespace App\Downloads;

use App\Plugins\AssetAvailabilityRepository;
use App\Plugins\ExternalInputPluginRegistry;
use App\Plugins\PluginAssetCapability;
use App\Support\PrefixedUlid;
use App\Vault\AssetRepository;
use App\Vault\AssetState;
use App\Vault\ItemId;
use App\Vault\ItemRepository;
use App\Vault\StageDownloadFiles;
use App\Providers\StashdUri;

final readonly class AcquireItemAssets
{
    public function __construct(
        private ItemRepository $items,
        private AssetRepository $assets,
        private ExternalInputPluginRegistry $plugins,
        private AssetAvailabilityRepository $availability,
        private DownloadItem $ingester,
        private StageDownloadFiles $staging,
    ) {}

    /**
     * @param list<string>|null $requestedRoles
     * @param array<string, bool|string> $options
     */
    public function execute(ItemId $itemId, PrefixedUlid $jobId, ?array $requestedRoles = null, array $options = []): AssetAcquisitionResult
    {
        $item = $this->items->find($itemId)
            ?? throw DownloadException::withCode('item_not_found', 'Item not found.');
        $plugin = $this->plugins->findDownloader($item->providerKey);
        $definition = $this->plugins->definitionForProvider($item->providerKey);

        if ($plugin === null || $definition === null) {
            throw DownloadException::withCode('acquisition_provider_unavailable', 'No Input plugin is available for this item.');
        }

        $capabilities = array_values(array_filter(
            $definition->assetCapabilities,
            static fn(mixed $capability): bool => $capability instanceof PluginAssetCapability
                && ($requestedRoles === null || in_array($capability->role, $requestedRoles, true)),
        ));

        if ($capabilities === []) {
            return new AssetAcquisitionResult();
        }

        $roles = array_values(array_unique(array_map(static fn(PluginAssetCapability $capability): string => $capability->role, $capabilities)));
        $staging = $this->staging->createWorkDirectory($jobId);

        try {
            $result = $plugin->acquireAssets(
                item: $this->wireItem($item),
                staging: $staging,
                mediaKind: $item->contentType === 'audio' ? 'audio' : 'video',
                options: $options,
                requestedRoles: $roles,
            );
            $ingestable = array_values(array_filter(
                $result->files,
                fn(DownloadedFile $file): bool => $this->capabilityFor($capabilities, $file->role) !== null
                    && (($existing = $this->assets->findByItemAndRole($itemId, $file->role)) === null
                        || ! in_array($existing->state, [AssetState::Ready, AssetState::Stale], true)),
            ));

            if ($ingestable !== []) {
                $this->ingester->ingestAcquiredFiles($itemId, $jobId, $ingestable, $plugin->implementationName(), $plugin->implementationVersion());
            }

            foreach ($result->files as $file) {
                $capability = $this->capabilityFor($capabilities, $file->role);

                if ($capability !== null) {
                    $this->availability->clear($itemId, $capability, $plugin->implementationVersion() ?? '');
                }
            }

            $temporary = [];

            foreach ($result->unavailable as $unavailable) {
                $capability = $this->capabilityFor($capabilities, $unavailable->role);

                if ($capability === null) {
                    continue;
                }

                $this->availability->record($itemId, $capability, $plugin->implementationVersion() ?? '', $unavailable->permanent, $unavailable->message);

                if (! $unavailable->permanent) {
                    $temporary[] = $unavailable->message;
                }
            }

            if ($temporary !== []) {
                throw DownloadException::withCode('asset_temporarily_unavailable', implode(' ', $temporary), retryable: true);
            }

            $this->staging->cleanupSuccess($staging);

            return $result;
        } catch (\Throwable $throwable) {
            $this->staging->markFailed($staging);

            throw $throwable;
        }
    }

    /** @param list<PluginAssetCapability> $capabilities */
    private function capabilityFor(array $capabilities, \App\Vault\AssetRole $role): ?PluginAssetCapability
    {
        foreach ($capabilities as $capability) {
            if ($capability->assetRole === $role) {
                return $capability;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function wireItem(\App\Vault\ItemRecord $item): array
    {
        return [
            'id' => $item->providerItemId,
            'reference' => StashdUri::parse($item->canonicalUri)->toString(),
            'title' => $item->title,
            'description' => $item->description,
            'published-at' => $item->publishedAt?->toRfc3339(useZ: true),
            'artwork-reference' => $item->thumbnailUri,
            'duration-seconds' => $item->duration === null ? null : (int) $item->duration->getTotalSeconds(),
            'kind' => $item->contentType,
            'size-bytes' => null,
            'size-estimated' => false,
            'upstream-state' => null,
        ];
    }
}
