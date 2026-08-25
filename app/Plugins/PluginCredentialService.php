<?php

declare(strict_types=1);

namespace App\Plugins;

use App\System\Secret\SecretsService;

final readonly class PluginCredentialService
{
    public function __construct(
        private ExternalInputPluginRegistry $plugins,
        private SecretsService $secrets,
    ) {}

    /** @return list<array{key: string, label: string, credentials: list<array<string, bool|string|null>>}> */
    public function list(): array
    {
        $plugins = [];

        foreach ($this->plugins->definitions() as $plugin) {
            if ($plugin->credentials === []) {
                continue;
            }
            $plugins[] = [
                'key' => $plugin->id,
                'label' => $plugin->name,
                'credentials' => array_map(
                    fn(PluginCredentialDefinition $credential): array => $credential->toArray($this->secrets->has($credential->secretKey)),
                    $plugin->credentials,
                ),
            ];
        }

        return $plugins;
    }

    public function replace(string $pluginKey, string $credentialKey, #[\SensitiveParameter] string $value): ?PluginCredentialDefinition
    {
        $plugin = $this->plugins->definition($pluginKey);
        $credential = $plugin?->credential($credentialKey);

        if ($credential === null) {
            return null;
        }

        $this->secrets->put($credential->secretKey, $credential->secretType, $value, [
            'plugin_key' => $plugin->id,
            'credential_key' => $credential->key,
        ]);

        return $credential;
    }
}
