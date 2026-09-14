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
use InvalidArgumentException;

use function Tempest\Support\str;

final readonly class DiscoverStashInput
{
    public function __construct(
        private ProviderRegistry $providers,
        private ProviderStrategySelector $strategySelector,
    ) {}

    /** @param array<string, mixed> $payload */
    public function execute(array $payload, ?JobType $intent = null, ?callable $onProgress = null, ?callable $onDiscovered = null): InputPreflightResult
    {
        $intent ??= JobType::core('core.preflight');
        $discoveryIntent = ApiJson::string($payload['discovery_intent'] ?? null, 'refresh');

        if (! in_array($discoveryIntent, ['refresh', 'complete'], true)) {
            throw new \InvalidArgumentException('Unsupported discovery intent.');
        }
        $sourceUri = str(ApiJson::string($payload['source_uri'] ?? null))->trim()->toString();
        $sourceTitle = isset($payload['source_title']) && is_string($payload['source_title']) && str($payload['source_title'])->trim()->isNotEmpty()
            ? str($payload['source_title'])->trim()->toString()
            : null;
        $uri = StashdUri::parse($sourceUri);
        $provider = $this->providers->resolveForUri($uri);
        $resolved = $provider->resolveInput($uri);

        return $this->executeResolved(
            $resolved,
            $sourceUri,
            $sourceTitle,
            $payload['provider_options'] ?? null,
            $intent,
            $onProgress,
            $onDiscovered,
            ($payload['backfill_missing'] ?? false) === true,
            $discoveryIntent,
        );
    }

    public function executeResolved(ResolvedInput $resolved, string $sourceUri, ?string $sourceTitle, mixed $providerOptions, ?JobType $intent = null, ?callable $onProgress = null, ?callable $onDiscovered = null, bool $backfillMissing = false, string $discoveryIntent = 'refresh'): InputPreflightResult
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

        // Preflight must use the complete strategy so its item count and
        // storage estimate are meaningful. Routine sync remains incremental.
        $selectionOptions = match ($intent->value) {
            'core.preflight', 'core.initial_backfill' => new StrategySelectionOptions(preferHighestCapability: true),
            'core.sync_input' => $backfillMissing || $discoveryIntent === 'complete'
                ? new StrategySelectionOptions(preferHighestCapability: true)
                : new StrategySelectionOptions(preferIncremental: true),
            default => null,
        };

        try {
            $strategy = $this->strategySelector->select($provider, StrategyPurpose::Discovery, $selectionOptions);
        } catch (InvalidArgumentException $exception) {
            if ($intent->value !== 'core.sync_input' || $discoveryIntent !== 'complete') {
                throw $exception;
            }

            $strategy = $this->strategySelector->select($provider, StrategyPurpose::Discovery, new StrategySelectionOptions(preferIncremental: true));
        }
        $inputOptions = $provider->inputOptions($resolved);
        $reportDiscovered = $onDiscovered === null ? null : static function (DiscoveredItem $item) use ($onDiscovered, $resolved, $inputOptions): void {
            $onDiscovered($resolved, DiscoveredItem::toArray($item), $inputOptions);
        };

        /** @var list<DiscoveredItem> $discovered */
        $discovered = $provider->discover($resolved, $strategy, self::providerOptions($providerOptions), $onProgress, $reportDiscovered);

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
            inputOptions: $inputOptions,
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
