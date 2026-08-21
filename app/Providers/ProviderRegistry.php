<?php

declare(strict_types=1);

namespace App\Providers;

use App\Plugins\ExternalInputPluginRegistry;
use App\Providers\Fake\FakeProvider;
use InvalidArgumentException;
use Tempest\Container\Singleton;

#[Singleton]
final class ProviderRegistry
{
    /** @var array<string, Provider> */
    private array $providers = [];

    public function __construct(
        FakeProvider $fakeProvider,
        ?ExternalInputPluginRegistry $externalPlugins = null,
    ) {
        $this->register($fakeProvider);
        foreach ($externalPlugins?->providers() ?? [] as $plugin) {
            $this->register($plugin);
        }
    }

    public function register(Provider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function get(string $key): Provider
    {
        return $this->providers[$key]
            ?? throw new InvalidArgumentException("Unknown provider: {$key}");
    }

    /** @return list<Provider> */
    public function all(): array
    {
        return array_values($this->providers);
    }

    public function resolveForUri(StashdUri $uri): Provider
    {
        foreach ($this->providers as $provider) {
            if ($provider->supportsUri($uri)) {
                return $provider;
            }
        }

        throw ProviderException::withUnsupportedUrl($uri->toString(), 'No provider supports this URL.');
    }
}
