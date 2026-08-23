<?php

declare(strict_types=1);

namespace App\Plugins;

final readonly class WasmtimeBroadcastRuntime implements BroadcastPluginRuntime
{
    public function __construct(
        private PluginHostClient $host,
        private string $componentPath,
    ) {}

    /** @param list<PluginHttpGrant>|null $httpGrants */
    public function prepare(string $stagingDirectory, array $broadcast, ?PluginHelperGrant $helper, ?array $httpGrants, ?string $fixtureDirectory): PluginBroadcastResult
    {
        return $this->host->prepareBroadcast($this->componentPath, $stagingDirectory, $broadcast, $helper, $httpGrants, $fixtureDirectory);
    }

    /** @param list<PluginHttpGrant>|null $httpGrants */
    public function publish(string $stagingDirectory, array $broadcast, ?PluginHelperGrant $helper, ?array $httpGrants, ?string $fixtureDirectory): PluginBroadcastResult
    {
        return $this->host->publishBroadcast($this->componentPath, $stagingDirectory, $broadcast, $helper, $httpGrants, $fixtureDirectory);
    }

    /** @param list<PluginHttpGrant>|null $httpGrants */
    public function finalize(string $stagingDirectory, array $broadcast, array $publication, ?array $httpGrants, ?string $fixtureDirectory): PluginBroadcastResult
    {
        return $this->host->finalizeBroadcast($this->componentPath, $stagingDirectory, $broadcast, $publication, $httpGrants, $fixtureDirectory);
    }

    /** @param list<PluginHttpGrant>|null $httpGrants */
    public function operation(string $stagingDirectory, array $broadcast, string $operation, ?array $httpGrants, ?string $fixtureDirectory): array
    {
        return $this->host->broadcastOperation($this->componentPath, $stagingDirectory, $broadcast, $operation, $httpGrants, $fixtureDirectory);
    }
}
