<?php

declare(strict_types=1);

use App\Jobs\JobType;
use App\Providers\Provider;
use App\Providers\ProviderRegistry;
use App\Providers\ProviderStrategy;
use App\Providers\ProviderStrategySelector;
use App\Providers\ResolvedInput;
use App\Providers\StashdUri;
use App\Providers\StrategyCost;
use App\Providers\StrategyPurpose;
use App\Stashes\DiscoverStashInput;

test('routine refresh stays incremental when existing items have missing metadata while complete discovery uses the complete strategy', function (): void {
    $provider = new class implements Provider {
        public function key(): string { return 'plugin'; }
        public function name(): string { return 'Plugin'; }
        public function supportsUri(StashdUri $uri): bool { return true; }
        public function resolveInput(StashdUri $uri): ResolvedInput { return new ResolvedInput('plugin', 'channel', $uri, 'channel-1'); }
        public function discoveryStrategies(): array
        {
            return [
                new ProviderStrategy('plugin.refresh', StrategyPurpose::Discovery, StrategyCost::Low, supportsIncremental: true),
                new ProviderStrategy('plugin.complete', StrategyPurpose::Discovery, StrategyCost::Medium),
            ];
        }
        public function metadataStrategies(): array { return []; }
        public function downloadStrategies(): array { return []; }
        public function discover(ResolvedInput $input, ProviderStrategy $strategy, array $options = [], ?callable $onProgress = null, ?callable $onDiscovered = null): array { return []; }
        public function isStrategyAvailable(ProviderStrategy $strategy): bool { return true; }
        public function inputOptions(ResolvedInput $input): array { return []; }
    };
    $providers = (new ReflectionClass(ProviderRegistry::class))->newInstanceWithoutConstructor();
    $providers->register($provider);
    $discovery = new DiscoverStashInput($providers, new ProviderStrategySelector());
    $resolved = new ResolvedInput('plugin', 'channel', StashdUri::parse('plugin://channel/1'), 'channel-1');

    $refresh = $discovery->executeResolved($resolved, 'plugin://channel/1', null, null, JobType::core('core.sync_input'), backfillMissing: true);
    $complete = $discovery->executeResolved($resolved, 'plugin://channel/1', null, null, JobType::core('core.sync_input'), discoveryIntent: 'complete');

    expect($refresh->strategyKey)->toBe('plugin.refresh')
        ->and($complete->strategyKey)->toBe('plugin.complete');
});
