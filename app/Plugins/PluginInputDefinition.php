<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Providers\InputOption;
use App\Providers\InputOptionType;
use App\System\Secret\SecretsService;
use RuntimeException;

final readonly class PluginInputDefinition
{
    public function __construct(public string $id, public string $providerKey, public string $name, public string $version, public string $root, public array $prefixes, public array $grants, public array $options, public ?PluginHelperGrant $helper) {}

    public static function from(array $manifest, string $root): ?self
    {
        if (($manifest['kind'] ?? null) !== 'input' || ! is_string($manifest['id'] ?? null)) {
            return null;
        }
        $options = [];
        foreach (is_array($manifest['input_options'] ?? null) ? $manifest['input_options'] : [] as $raw) {
            if (! is_array($raw) || ! is_string($raw['key'] ?? null) || ! is_string($raw['label'] ?? null)) {
                continue;
            }
            $type = InputOptionType::tryFrom((string) ($raw['type'] ?? ''));
            if ($type === null || ! is_bool($raw['default'] ?? null) && ! is_string($raw['default'] ?? null)) {
                continue;
            }
            $options[] = new InputOption($raw['key'], $raw['label'], $type, $raw['default'], is_array($raw['choices'] ?? null) ? $raw['choices'] : null, is_array($raw['applicable_input_types'] ?? null) ? $raw['applicable_input_types'] : [], [], $raw['description'] ?? null);
        }
        $helper = null;
        $declared = is_array($manifest['helpers'] ?? null) ? $manifest['helpers'] : [];
        if ($declared === [] && is_array($manifest['helper'] ?? null)) {
            $declared = [(string) ($manifest['helper']['name'] ?? '') => $manifest['helper']];
        }
        foreach ($declared as $name => $definition) {
            if (! is_string($name) || ! is_array($definition) || ! is_string($definition['executable'] ?? null)) {
                continue;
            }
            $relative = ltrim($definition['executable'], '/');
            if (str_contains($relative, '..') || str_contains($relative, "\0")) {
                throw new RuntimeException('Plugin helper paths must be package-relative.');
            }
            $path = $root . '/' . $relative;
            if (is_file($path)) {
                $helper ??= new PluginHelperGrant($name, $path, $root, (bool) ($definition['network'] ?? false));
            }
        }

        return new self((string) $manifest['id'], (string) ($manifest['provider_key'] ?? $manifest['id']), (string) ($manifest['name'] ?? $manifest['id']), (string) ($manifest['version'] ?? '0.0.0'), $root, is_array($manifest['source_prefixes'] ?? null) ? $manifest['source_prefixes'] : [], is_array($manifest['http_grants'] ?? null) ? $manifest['http_grants'] : [], $options, $helper);
    }

    /** @return list<PluginHttpGrant> */
    public function httpGrants(SecretsService $secrets, string $operation): array
    {
        $result = [];
        foreach ($this->grants as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $ops = is_array($raw['operations'] ?? null) ? $raw['operations'] : [];
            if ($ops !== [] && ! in_array($operation, $ops, true)) {
                continue;
            }
            $prefixes = is_array($raw['allowed_prefixes'] ?? null) ? array_values(array_filter($raw['allowed_prefixes'], 'is_string')) : [];
            $credential = null;
            if (is_array($raw['credential'] ?? null)) {
                $c = $raw['credential'];
                $value = is_string($c['secret_key'] ?? null) ? $secrets->get($c['secret_key']) : null;
                if ($value !== null && $value !== '') {
                    $credential = new PluginCredentialGrant((string) ($c['name'] ?? ''), $value, (string) ($c['parameter'] ?? 'key'), (string) ($c['placement'] ?? 'query'));
                }
            }
            $result[] = new PluginHttpGrant($prefixes, $credential);
        }

        return $result;
    }
}
