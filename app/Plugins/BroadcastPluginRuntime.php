<?php

declare(strict_types=1);

namespace App\Plugins;

interface BroadcastPluginRuntime
{
    /** @param array<string, mixed> $broadcast
     * @param  list<PluginHttpGrant>|null  $httpGrants
     */
    public function prepare(string $stagingDirectory, array $broadcast, ?PluginHelperGrant $helper, ?array $httpGrants, ?string $fixtureDirectory): PluginBroadcastResult;

    /** @param array<string, mixed> $broadcast
     * @param  list<PluginHttpGrant>|null  $httpGrants
     */
    public function publish(string $stagingDirectory, array $broadcast, ?PluginHelperGrant $helper, ?array $httpGrants, ?string $fixtureDirectory): PluginBroadcastResult;

    /** @param array<string, mixed> $broadcast
     * @param  array<string, mixed>  $publication
     * @param  list<PluginHttpGrant>|null  $httpGrants
     */
    public function finalize(string $stagingDirectory, array $broadcast, array $publication, ?array $httpGrants, ?string $fixtureDirectory): PluginBroadcastResult;

    /** @param array<string, mixed> $broadcast
     * @param  list<PluginHttpGrant>|null  $httpGrants
     * @return array<string, mixed>
     */
    public function operation(string $stagingDirectory, array $broadcast, string $operation, ?array $httpGrants, ?string $fixtureDirectory): array;
}
