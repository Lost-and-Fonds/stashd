<?php

declare(strict_types=1);

namespace App\Providers\Fake;

use App\Plugins\SourceResolvingInputProvider;
use App\Providers\Core\DiscoveredItem;
use App\Providers\Provider;
use App\Providers\ProviderDates;
use App\Providers\ProviderStrategy;
use App\Providers\ResolvedInput;
use App\Providers\StashdUri;
use App\Providers\StrategyCost;
use App\Providers\StrategyPurpose;
use InvalidArgumentException;
use RuntimeException;

use function Tempest\Support\str;

/**
 * Deterministic fake provider for tests and local development.
 *
 * URI patterns:
 *   fake://channel/{slug}   — 3 items, 4 once advanceSyncGeneration() bumps it
 *                             to generation 2+ (simulates a routine resync
 *                             discovering a new episode; calling discover()
 *                             itself never advances the generation, so an
 *                             unrelated extra call — e.g. a preview before a
 *                             real commit — can't accidentally fabricate items)
 *   fake://playlist/{slug}  — 20 items
 *   fake://fail/metadata    — metadata failure simulation
 *   fake://fail/download    — download failure simulation
 *   fake://fail/rate-limit  — rate limit simulation
 *   fake://item/private     — private item simulation
 *   fake://item/deleted     — deleted item simulation
 */
final class FakeProvider implements Provider, SourceResolvingInputProvider
{
    public const string KEY = 'fake';

    /** @var array<string, int> */
    private array $syncGenerations = [];

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Fake Provider';
    }

    public function supportsUri(StashdUri $uri): bool
    {
        return $uri->scheme() === 'fake';
    }

    public function resolveInput(StashdUri $uri): ResolvedInput
    {
        $type = $uri->host();
        $id = str($uri->path())->trim('/')->toString();

        if ($type === '' || $id === '') {
            throw new InvalidArgumentException('Fake URI must use fake://{type}/{id} format.');
        }

        return new ResolvedInput(
            providerKey: self::KEY,
            inputType: $type,
            sourceUri: $uri,
            providerInputId: "{$type}:{$id}",
            title: "Fake {$type} {$id}",
        );
    }

    public function resolveSource(array $source): ResolvedInput
    {
        $reference = $source['reference'] ?? null;

        if (! is_string($reference)) {
            throw new InvalidArgumentException('Fake source reference is required.');
        }

        return $this->resolveInput(StashdUri::parse($reference));
    }

    public function discoveryStrategies(): array
    {
        return [
            new ProviderStrategy(
                key: 'fake.feed',
                purpose: StrategyPurpose::Discovery,
                cost: StrategyCost::Low,
                supportsIncremental: true,
                supportsBackfill: true,
                priority: 10,
            ),
        ];
    }

    public function metadataStrategies(): array
    {
        return [
            new ProviderStrategy(
                key: 'fake.metadata',
                purpose: StrategyPurpose::Metadata,
                cost: StrategyCost::Low,
                priority: 10,
            ),
        ];
    }

    public function downloadStrategies(): array
    {
        return [
            new ProviderStrategy(
                key: 'fake.download',
                purpose: StrategyPurpose::Download,
                cost: StrategyCost::Low,
                priority: 10,
            ),
        ];
    }

    /** @return list<DiscoveredItem> */
    public function discover(ResolvedInput $input, ProviderStrategy $strategy, array $options = [], ?callable $onProgress = null): array
    {
        if ($strategy->key !== 'fake.feed') {
            throw new InvalidArgumentException("Unsupported fake discovery strategy: {$strategy->key}");
        }

        return match ($input->inputType) {
            'channel' => $this->discoverChannel($input),
            'playlist' => $this->discoverPlaylist($input),
            'fail' => throw new RuntimeException('Fake provider rate limit exceeded.', 429),
            'item' => $this->discoverSingleItem($input),
            default => throw new InvalidArgumentException("Unsupported fake input type: {$input->inputType}"),
        };
    }

    /** @return list<DiscoveredItem> */
    private function discoverChannel(ResolvedInput $input): array
    {
        $slug = explode(':', $input->providerInputId)[1] ?? 'default';
        $generation = $this->syncGenerations[$input->providerInputId] ?? 1;
        $count = $generation >= 2 ? 4 : 3;

        $items = [];

        for ($i = 1; $i <= $count; $i++) {
            $items[] = new DiscoveredItem(
                providerItemId: "{$slug}-episode-{$i}",
                canonicalUri: StashdUri::fake("item/{$slug}-episode-{$i}"),
                title: "Fake Episode {$i}",
                description: "Fake episode {$i} description.",
                durationSeconds: 600 + ($i * 30),
                publishedAt: ProviderDates::utc(sprintf('2026-01-%02dT12:00:00Z', min($i, 28))),
            );
        }

        return $items;
    }

    /** @return list<DiscoveredItem> */
    private function discoverPlaylist(ResolvedInput $input): array
    {
        $slug = explode(':', $input->providerInputId)[1] ?? 'default';
        $items = [];

        for ($i = 1; $i <= 20; $i++) {
            $items[] = new DiscoveredItem(
                providerItemId: "{$slug}-track-{$i}",
                canonicalUri: StashdUri::fake("item/{$slug}-track-{$i}"),
                title: "Fake Track {$i}",
                description: "Fake track {$i} description.",
                durationSeconds: 180 + $i,
                publishedAt: ProviderDates::utc(sprintf('2025-12-%02dT08:00:00Z', min($i, 28))),
            );
        }

        return $items;
    }

    /** @return list<DiscoveredItem> */
    private function discoverSingleItem(ResolvedInput $input): array
    {
        $kind = explode(':', $input->providerInputId)[1] ?? 'unknown';

        return match ($kind) {
            'private' => throw new RuntimeException('Fake item is private.'),
            'deleted' => throw new RuntimeException('Fake item was deleted upstream.'),
            default => [
                new DiscoveredItem(
                    providerItemId: $kind,
                    canonicalUri: StashdUri::fake("item/{$kind}"),
                    title: "Fake Item {$kind}",
                    description: "Fake item {$kind} description.",
                    durationSeconds: 420,
                    publishedAt: ProviderDates::utc('2026-06-01T10:00:00Z'),
                ),
            ],
        };
    }

    public function isStrategyAvailable(ProviderStrategy $strategy): bool
    {
        return in_array($strategy->key, ['fake.feed', 'fake.metadata', 'fake.download'], true);
    }

    /**
     * Advances a channel's simulated sync generation, so its next
     * `discover()` call returns the grown item set. Test-only; production
     * discovery never calls this.
     */
    public function advanceSyncGeneration(string $providerInputId): void
    {
        $this->syncGenerations[$providerInputId] = ($this->syncGenerations[$providerInputId] ?? 1) + 1;
    }

    public function inputOptions(ResolvedInput $input): array
    {
        return [];
    }

    public function resetSyncGenerations(): void
    {
        $this->syncGenerations = [];
    }
}
