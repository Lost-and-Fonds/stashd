<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Jobs\JobIntent;
use App\Http\Api\ApiJson;
use App\Providers\Core\DiscoveredItem;
use App\Providers\ProviderRegistry;
use App\Providers\ProviderStrategySelector;
use App\Providers\ResolvedInput;
use App\Providers\StashdUri;
use App\Providers\StrategyPurpose;
use App\Providers\StrategySelectionOptions;

use function Tempest\Support\str;

final readonly class DiscoverStashInput
{
    public function __construct(
        private ProviderRegistry $providers,
        private ProviderStrategySelector $strategySelector,
    ) {}

    /** @param array<string, mixed> $payload */
    public function execute(array $payload, JobIntent $intent = JobIntent::Preflight): PreflightExecutionResult
    {
        $sourceUri = str(ApiJson::string($payload['source_uri'] ?? null))->trim()->toString();
        $sourceTitle = isset($payload['source_title']) && is_string($payload['source_title']) && str($payload['source_title'])->trim()->isNotEmpty()
            ? str($payload['source_title'])->trim()->toString()
            : null;
        $origin = PreflightOrigin::tryFrom(ApiJson::string($payload['origin'] ?? null)) ?? PreflightOrigin::Api;

        $uri = StashdUri::parse($sourceUri);
        $provider = $this->providers->resolveForUri($uri);
        $resolved = $provider->resolveInput($uri);

        return $this->executeResolved($resolved, $sourceUri, $sourceTitle, $origin, $payload['provider_options'] ?? null, $intent);
    }

    public function executeResolved(ResolvedInput $resolved, string $sourceUri, ?string $sourceTitle, PreflightOrigin $origin, mixed $providerOptions, JobIntent $intent = JobIntent::Preflight): PreflightExecutionResult
    {
        $provider = $this->providers->get($resolved->providerKey);

        if ($sourceTitle !== null) {
            $resolved = new ResolvedInput(
                providerKey: $resolved->providerKey,
                inputType: $resolved->inputType,
                sourceUri: $resolved->sourceUri,
                providerInputId: $resolved->providerInputId,
                title: $sourceTitle,
                sourceTitle: $resolved->sourceTitle,
                sourceAvatarUri: $resolved->sourceAvatarUri,
                estimatedItemCount: $resolved->estimatedItemCount,
            );
        }

        // Preflight must prefer the same strategy as the later commit
        // (InitialBackfill) -- otherwise the items previewed here can differ
        // from what actually gets persisted once a stronger plugin capability
        // is available. SyncInput belongs here for the same reason: falling
        // back to a cheap strategy could shrink a source on every sync.
        // Strategies still gate their own availability (e.g. no key
        // configured), so this is a no-op when only the cheap one exists.
        $selectionOptions = match ($intent) {
            JobIntent::Preflight, JobIntent::InitialBackfill => new StrategySelectionOptions(preferHighestCapability: true),
            JobIntent::SyncInput => new StrategySelectionOptions(preferHighestCapability: true),
            default => null,
        };
        $strategy = $this->strategySelector->select($provider, StrategyPurpose::Discovery, $selectionOptions);
        /** @var list<DiscoveredItem> $discovered */
        $discovered = $provider->discover($resolved, $strategy, self::providerOptions($providerOptions));

        if ($sourceTitle === null && $resolved->inputType === 'playlist') {
            $inputTitle = $this->playlistTitle($discovered);

            if ($inputTitle !== null) {
                $resolved = new ResolvedInput(
                    providerKey: $resolved->providerKey,
                    inputType: $resolved->inputType,
                    sourceUri: $resolved->sourceUri,
                    providerInputId: $resolved->providerInputId,
                    title: $inputTitle,
                    sourceTitle: $resolved->sourceTitle,
                    sourceAvatarUri: $resolved->sourceAvatarUri,
                    estimatedItemCount: $resolved->estimatedItemCount,
                    sizeBytes: $resolved->sizeBytes,
                    sizeEstimated: $resolved->sizeEstimated,
                );
            }
        }

        $discoveredItems = DiscoveredItem::manyToArray($discovered);

        $estimatedItemCount = count($discovered);
        $estimatedDuration = array_sum(array_map(
            static fn(DiscoveredItem $item): int => $item->durationSeconds ?? 0,
            $discovered,
        ));

        return new PreflightExecutionResult(
            sourceUri: $sourceUri,
            sourceTitle: $sourceTitle,
            origin: $origin,
            resolvedInput: $resolved,
            strategyKey: $strategy->key,
            estimatedItemCount: $estimatedItemCount,
            estimatedTotalDurationSeconds: $estimatedDuration,
            discoveredItems: $discoveredItems,
            inputOptions: $provider->inputOptions($resolved),
        );
    }

    /** @param list<DiscoveredItem> $items */
    private function playlistTitle(array $items): ?string
    {
        foreach ($items as $item) {
            $title = $item->rawMetadata['input_title'] ?? null;

            if (is_string($title) && str($title)->trim()->isNotEmpty()) {
                return str($title)->trim()->toString();
            }
        }

        return null;
    }

    /** @return array<string, bool|string> */
    private static function providerOptions(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $options = [];

        foreach ($value as $key => $option) {
            if (is_string($key) && (is_bool($option) || is_string($option))) {
                $options[$key] = $option;
            }
        }

        return $options;
    }
}
