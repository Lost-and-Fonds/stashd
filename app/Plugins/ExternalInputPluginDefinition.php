<?php

declare(strict_types=1);

namespace App\Plugins;

use App\System\Secret\SecretsService;
use RuntimeException;

final readonly class ExternalInputPluginDefinition
{
    /**
     * @param list<string> $sourcePrefixes
     * @param list<array<string, mixed>> $httpGrants
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public string $componentPath,
        public string $socketPath,
        public array $sourcePrefixes,
        public array $httpGrants,
        public ?string $helperName,
        public ?string $helperExecutable,
    ) {
    }

    public static function fromManifest(mixed $rawManifest, string $root, string $socketPath): self
    {
        $manifest = self::stringMap($rawManifest);
        $string = static function (array $values, string $key, string $default = ''): string {
            $value = $values[$key] ?? $default;

            return is_string($value) && trim($value) !== '' ? trim($value) : $default;
        };

        $id = $string($manifest, 'id');
        $component = self::environmentOverride($manifest, 'component') ?? $string($manifest, 'component');

        if ($id === '' || $component === '') {
            throw new RuntimeException('External Input plugin manifest requires id and component.');
        }

        $sourcePrefixes = array_values(array_filter(
            is_array($manifest['source_prefixes'] ?? null) ? $manifest['source_prefixes'] : [],
            static fn (mixed $prefix): bool => is_string($prefix) && trim($prefix) !== '',
        ));
        $httpGrants = self::mapList($manifest['http_grants'] ?? []);
        $helper = self::stringMapOrNull($manifest['helper'] ?? null);

        return new self(
            id: $id,
            name: $string($manifest, 'name', $id),
            version: $string($manifest, 'version', '0.0.0'),
            componentPath: self::resolvePath($root, $component),
            socketPath: $socketPath,
            sourcePrefixes: $sourcePrefixes,
            httpGrants: $httpGrants,
            helperName: $helper !== null ? $string($helper, 'name') : null,
            helperExecutable: $helper !== null
                ? self::environmentOverride($helper, 'executable')
                : null,
        );
    }

    /** @return list<PluginHttpGrant> */
    public function httpGrants(SecretsService $secrets, string $operation): array
    {
        $grants = [];

        foreach ($this->httpGrants as $definition) {
            $operations = $definition['operations'] ?? ['resolve', 'refresh', 'complete', 'acquire'];
            if (! is_array($operations) || ! in_array($operation, $operations, true)) {
                continue;
            }

            $prefixes = array_values(array_filter(
                is_array($definition['allowed_prefixes'] ?? null) ? $definition['allowed_prefixes'] : [],
                static fn (mixed $prefix): bool => is_string($prefix) && trim($prefix) !== '',
            ));
            if ($prefixes === []) {
                continue;
            }

            $credential = null;
            $credentialDefinition = self::stringMapOrNull($definition['credential'] ?? null);
            if ($credentialDefinition !== null) {
                $secretKey = self::stringValue($credentialDefinition, 'secret_key');
                $environment = self::stringValue($credentialDefinition, 'environment');
                $value = $secretKey !== '' ? $secrets->get($secretKey) : null;
                if (($value === null || trim($value) === '') && $environment !== '') {
                    $environmentValue = getenv($environment);
                    $value = is_string($environmentValue) ? $environmentValue : null;
                }

                if ($value !== null && trim($value) !== '') {
                    $name = self::stringValue($credentialDefinition, 'name');
                    $parameter = self::stringValue($credentialDefinition, 'query_parameter');
                    if ($name !== '' && $parameter !== '') {
                        $credential = new PluginCredentialGrant(trim($name), trim($value), trim($parameter));
                    }
                }
            }

            $grants[] = new PluginHttpGrant($prefixes, $credential);
        }

        return $grants;
    }

    public function hasCredential(SecretsService $secrets, string $operation): bool
    {
        foreach ($this->httpGrants($secrets, $operation) as $grant) {
            if ($grant->credential !== null) {
                return true;
            }
        }

        return false;
    }

    public function helperExecutable(): ?string
    {
        return $this->helperExecutable !== null && trim($this->helperExecutable) !== ''
            ? $this->helperExecutable
            : null;
    }

    private static function resolvePath(string $root, string $path): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($root, '/') . '/' . ltrim($path, '/');
    }

    /** @param array<string, mixed> $values */
    private static function environmentOverride(array $values, string $key): ?string
    {
        $environment = self::stringValue($values, $key . '_environment');
        if ($environment !== '') {
            $value = getenv($environment);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $value = self::stringValue($values, $key);

        return $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $values */
    private static function stringValue(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    /** @return array<string, mixed> */
    private static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('External Input plugin manifest must be an object.');
        }

        $map = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $map[$key] = $item;
            }
        }

        return $map;
    }

    /** @return array<string, mixed>|null */
    private static function stringMapOrNull(mixed $value): ?array
    {
        return $value === null ? null : self::stringMap($value);
    }

    /** @return list<array<string, mixed>> */
    private static function mapList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $list[] = self::stringMap($item);
            }
        }

        return $list;
    }
}
