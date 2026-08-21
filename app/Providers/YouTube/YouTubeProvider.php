<?php

declare(strict_types=1);

namespace App\Providers\YouTube;

use App\Providers\Core\DiscoveredItem;
use App\Providers\InputOption;
use App\Providers\InputOptionType;
use App\Providers\Provider;
use App\Providers\ProviderException;
use App\Providers\ProviderStrategy;
use App\Providers\ResolvedInput;
use App\Providers\StashdUri;
use App\Providers\StrategyCost;
use App\Providers\StrategyPurpose;
use InvalidArgumentException;

final readonly class YouTubeProvider implements Provider
{
    public const string KEY = 'youtube';

    public function __construct(
        private YouTubeDataApiKeyResolver $dataApiKey,
        private YouTubeRssDiscoveryStrategy $rssDiscovery,
        private YouTubeDataApiDiscoveryStrategy $dataApiDiscovery,
        private YouTubeDataApiMetadataStrategy $dataApiMetadata,
        private YtdlpDownloadAdapter $downloadAdapter,
        private YouTubeYtdlpDiscoveryStrategy $ytdlpDiscovery,
        private YouTubeChannelIdResolver $channelIds,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'YouTube';
    }

    public function supportsUri(StashdUri $uri): bool
    {
        return YouTubeUriDetector::isYouTube($uri);
    }

    public function resolveInput(StashdUri $uri): ResolvedInput
    {
        $resolved = YouTubeUriResolver::resolve($uri);

        if ($resolved->inputType !== YouTubeInputType::Channel->value) {
            return $resolved;
        }

        $channel = $this->channelIds->resolve($resolved->providerInputId);

        return new ResolvedInput(
            providerKey: $resolved->providerKey,
            inputType: $resolved->inputType,
            sourceUri: $resolved->sourceUri,
            providerInputId: $channel->id,
            title: $resolved->title,
            sourceTitle: $channel->title,
            sourceAvatarUri: $channel->avatarUri !== null ? StashdUri::parse($channel->avatarUri) : null,
            estimatedItemCount: $channel->estimatedVideoCount,
        );
    }

    public function discoveryStrategies(): array
    {
        return [
            new ProviderStrategy(
                key: YouTubeRssDiscoveryStrategy::STRATEGY_KEY,
                purpose: StrategyPurpose::Discovery,
                cost: StrategyCost::Low,
                supportsIncremental: true,
                supportsBackfill: true,
                priority: 10,
            ),
            new ProviderStrategy(
                key: YouTubeDataApiDiscoveryStrategy::STRATEGY_KEY,
                purpose: StrategyPurpose::Discovery,
                cost: StrategyCost::Medium,
                requiresAuth: true,
                supportsIncremental: true,
                supportsBackfill: true,
                priority: 10,
            ),
            new ProviderStrategy(
                key: YouTubeYtdlpDiscoveryStrategy::STRATEGY_KEY,
                purpose: StrategyPurpose::Discovery,
                cost: StrategyCost::Medium,
                supportsIncremental: false,
                supportsBackfill: true,
                priority: 20,
            ),
        ];
    }

    public function metadataStrategies(): array
    {
        return [
            new ProviderStrategy(
                key: YouTubeDataApiMetadataStrategy::STRATEGY_KEY,
                purpose: StrategyPurpose::Metadata,
                cost: StrategyCost::Medium,
                requiresAuth: true,
                supportsBackfill: true,
                priority: 10,
            ),
        ];
    }

    public function downloadStrategies(): array
    {
        return [
            new ProviderStrategy(
                key: YouTubeYtdlpDownloadStrategy::STRATEGY_KEY,
                purpose: StrategyPurpose::Download,
                cost: StrategyCost::LastResort,
                priority: 100,
            ),
        ];
    }

    /** @return list<DiscoveredItem> */
    public function discover(ResolvedInput $input, ProviderStrategy $strategy, array $options = []): array
    {
        return match ($strategy->key) {
            YouTubeRssDiscoveryStrategy::STRATEGY_KEY => $this->rssDiscovery->discover($input),
            YouTubeDataApiDiscoveryStrategy::STRATEGY_KEY => $this->dataApiDiscovery->discover($input),
            YouTubeYtdlpDiscoveryStrategy::STRATEGY_KEY => $this->ytdlpDiscovery->discover($input),
            default => throw new InvalidArgumentException("Unsupported YouTube discovery strategy: {$strategy->key}"),
        };
    }

    public function isStrategyAvailable(ProviderStrategy $strategy): bool
    {
        return match ($strategy->key) {
            YouTubeRssDiscoveryStrategy::STRATEGY_KEY => true,
            YouTubeDataApiDiscoveryStrategy::STRATEGY_KEY => $this->dataApiKey->hasKey(),
            YouTubeDataApiMetadataStrategy::STRATEGY_KEY => $this->dataApiKey->hasKey(),
            YouTubeYtdlpDownloadStrategy::STRATEGY_KEY => $this->downloadAdapter->isAvailable(),
            YouTubeYtdlpDiscoveryStrategy::STRATEGY_KEY => $this->ytdlpDiscovery->isAvailable(),
            default => false,
        };
    }

    public function inputOptions(ResolvedInput $input): array
    {
        if ($input->inputType !== YouTubeInputType::Channel->value) {
            return [];
        }

        return [
            new InputOption(
                key: 'include_shorts',
                label: 'Include Shorts',
                type: InputOptionType::Bool,
                default: false,
                applicableInputTypes: [YouTubeInputType::Channel->value],
                excludesContentTypes: ['short'],
            ),
            new InputOption(
                key: 'include_live',
                label: 'Include live broadcasts and premieres',
                type: InputOptionType::Bool,
                default: false,
                applicableInputTypes: [YouTubeInputType::Channel->value],
                excludesContentTypes: ['live', 'premiere'],
            ),
        ];
    }

    public function enrichMetadata(ResolvedInput $input, DiscoveredItem $item, ProviderStrategy $strategy): DiscoveredItem
    {
        return match ($strategy->key) {
            YouTubeDataApiMetadataStrategy::STRATEGY_KEY => $this->dataApiMetadata->enrich($input, $item),
            YouTubeRssDiscoveryStrategy::STRATEGY_KEY, YouTubeYtdlpDiscoveryStrategy::STRATEGY_KEY => $item,
            default => throw new ProviderException("Unsupported YouTube metadata strategy: {$strategy->key}", 'unsupported_metadata_strategy'),
        };
    }
}
