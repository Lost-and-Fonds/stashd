<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Http\Api\ApiJson;
use App\Jobs\JobType;
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
    public function execute(array $payload, ?JobType $intent = null, ?callable $onProgress = null): InputPreflightResult
    {
        $intent ??= JobType::core('core.preflight');
        $sourceUri = str(ApiJson::string($payload['source_uri'] ?? null))->trim()->toString();
        $sourceTitle = isset($payload['source_title']) && is_string($payload['source_title']) && str($payload['source_title'])->trim()->isNotEmpty()
            ? str($payload['source_title'])->trim()->toString()
            : null;
        $uri = StashdUri::parse($sourceUri);
        $provider = $this->providers->resolveForUri($uri);
        $resolved = $provider->resolveInput($uri);

        return $this->executeResolved($resolved, $sourceUri, $sourceTitle, $payload['provider_options'] ?? null, $intent, $onProgress);
    }

    public function executeResolved(ResolvedInput $resolved, string $sourceUri, ?string $sourceTitle, mixed $providerOptions, ?JobType $intent = null, ?callable $onProgress = null): InputPreflightResult
    {
        $intent ??= JobType::core('core.preflight');
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

        // Preflight must prefer the same strategy as the later initial commit
        // (InitialBackfill) -- otherwise the items previewed here can differ
        // from what actually gets persisted once a stronger plugin capability
        // is available. A later sync is deliberately incremental: providers
        // use their cheap feed/check strategy and retain the complete backfill
        // as the initial discovery path.
        // Strategies still gate their own availability (e.g. no key
        // configured), so this is a no-op when only the cheap one exists.
        $selectionOptions = match ($intent->value) {
            'core.preflight', 'core.initial_backfill' => new StrategySelectionOptions(preferHighestCapability: true),
            'core.sync_input' => new StrategySelectionOptions(preferIncremental: true),
            default => null,
        };
        $strategy = $this->strategySelector->select($provider, StrategyPurpose::Discovery, $selectionOptions);
        /** @var list<DiscoveredItem> $discovered */
        $discovered = $provider->discover($resolved, $strategy, self::providerOptions($providerOptions), $onProgress);

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

        return new InputPreflightResult(
            sourceUri: $sourceUri,
            sourceTitle: $sourceTitle,
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
