<?php

declare(strict_types=1);

namespace App\Providers;

use App\Providers\Core\DiscoveredItem;

interface Provider
{
    public function key(): string;

    public function name(): string;

    public function supportsUri(StashdUri $uri): bool;

    public function resolveInput(StashdUri $uri): ResolvedInput;

    /** @return list<ProviderStrategy> */
    public function discoveryStrategies(): array;

    /** @return list<ProviderStrategy> */
    public function metadataStrategies(): array;

    /** @return list<ProviderStrategy> */
    public function downloadStrategies(): array;

    /** @return list<DiscoveredItem> */
    /** @param array<string, bool|string> $options
     * @return list<DiscoveredItem>
     */
    public function discover(ResolvedInput $input, ProviderStrategy $strategy, array $options = [], ?callable $onProgress = null): array;

    public function isStrategyAvailable(ProviderStrategy $strategy): bool;

    /** @return list<InputOption> */
    public function inputOptions(ResolvedInput $input): array;
}
