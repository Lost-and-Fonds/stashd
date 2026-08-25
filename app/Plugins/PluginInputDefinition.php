<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Providers\InputOption;
use App\Providers\InputOptionType;
use App\System\Secret\SecretsService;
use App\System\Secret\SecretType;
use RuntimeException;
use Tempest\Support\Filesystem;
use Tempest\Validation\Rules\IsArray;
use Tempest\Validation\Rules\IsString;
use Tempest\Validation\Validator;

final readonly class PluginInputDefinition
{
    /** @param list<string> $prefixes
     * @param list<mixed> $grants
     * @param list<InputOption> $options
     * @param list<PluginSourceField> $sourceFields
     * @param list<PluginCredentialDefinition> $credentials
     */
    public function __construct(public string $id, public string $providerKey, public string $name, public string $version, public string $root, public array $prefixes, public array $grants, public array $options, public array $sourceFields, public array $credentials, public ?PluginHelperGrant $helper) {}

    /** @param array<string, mixed> $manifest */
    public static function from(array $manifest, string $root): ?self
    {
        if (($manifest['kind'] ?? null) !== 'input' || ! is_string($manifest['id'] ?? null)) {
            return null;
        }
        $options = [];
        $validator = new Validator();

        foreach (is_array($manifest['input_options'] ?? null) ? $manifest['input_options'] : [] as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $raw = self::object($raw);

            if ($validator->validateValues($raw, [
                'key' => new IsString(),
                'label' => new IsString(),
                'choices' => new IsArray(orNull: true),
                'applicable_input_types' => new IsArray(orNull: true),
            ]) !== [] || ! is_string($raw['key'] ?? null) || ! is_string($raw['label'] ?? null)) {
                continue;
            }
            $typeValue = $raw['type'] ?? '';
            $type = is_string($typeValue) ? InputOptionType::tryFrom($typeValue) : null;
            $choices = $raw['choices'] ?? null;
            $applicableInputTypes = $raw['applicable_input_types'] ?? [];
            $description = $raw['description'] ?? null;

            if ($type === null || ! is_bool($raw['default'] ?? null) && ! is_string($raw['default'] ?? null) || $choices !== null && ! is_array($choices) || ! is_array($applicableInputTypes) || $description !== null && ! is_string($description)) {
                continue;
            }
            $options[] = new InputOption($raw['key'], $raw['label'], $type, $raw['default'], $choices === null ? null : array_values(array_filter($choices, static fn(mixed $value): bool => is_string($value))), array_values(array_filter($applicableInputTypes, static fn(mixed $value): bool => is_string($value))), [], $description);
        }
        $sourceFields = [];

        foreach (is_array($manifest['source_fields'] ?? null) ? $manifest['source_fields'] : [] as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $raw = self::object($raw);
            $key = $raw['key'] ?? null;
            $label = $raw['label'] ?? null;
            $type = $raw['type'] ?? null;
            $choices = $raw['choices'] ?? null;
            $description = $raw['description'] ?? null;

            if (! is_string($key) || ! is_string($label) || ! in_array($type, ['bool', 'number', 'text', 'enum'], true)
                || $choices !== null && (! is_array($choices) || array_filter($choices, 'is_string') !== $choices)
                || $description !== null && ! is_string($description)) {
                continue;
            }

            $sourceFields[] = new PluginSourceField($key, $label, $type, ($raw['required'] ?? false) === true, $choices === null ? null : array_values($choices), $description);
        }
        $credentials = [];

        foreach (is_array($manifest['credentials'] ?? null) ? $manifest['credentials'] : [] as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $raw = self::object($raw);
            $key = $raw['key'] ?? null;
            $label = $raw['label'] ?? null;
            $secretKey = $raw['secret_key'] ?? null;
            $description = $raw['description'] ?? null;
            $secretType = is_string($raw['secret_type'] ?? null) ? SecretType::tryFrom($raw['secret_type']) : SecretType::Generic;

            if (! is_string($key) || trim($key) === '' || ! is_string($label) || trim($label) === '' || ! is_string($secretKey) || trim($secretKey) === '' || $description !== null && ! is_string($description) || $secretType === null) {
                continue;
            }

            $credentials[] = new PluginCredentialDefinition(trim($key), trim($label), trim($secretKey), $secretType, ($raw['required'] ?? false) === true, $description);
        }
        $helper = null;
        $declared = is_array($manifest['helpers'] ?? null) ? $manifest['helpers'] : [];

        if ($declared === [] && is_array($manifest['helper'] ?? null)) {
            $helperName = is_string($manifest['helper']['name'] ?? null) ? $manifest['helper']['name'] : '';
            $declared = [$helperName => $manifest['helper']];
        }

        foreach ($declared as $name => $definition) {
            if (! is_string($name) || ! is_array($definition)) {
                continue;
            }
            $definition = self::object($definition);

            if ($validator->validateValues($definition, ['executable' => new IsString()]) !== [] || ! is_string($definition['executable'] ?? null)) {
                continue;
            }
            $relative = ltrim($definition['executable'], '/');

            if (str_contains($relative, '..') || str_contains($relative, "\0")) {
                throw new RuntimeException('Plugin helper paths must be package-relative.');
            }
            $path = $root . '/' . $relative;

            if (Filesystem\is_file($path)) {
                $helper ??= new PluginHelperGrant($name, $path, $root, (bool) ($definition['network'] ?? false));
            }
        }

        $id = $manifest['id'];
        $providerKey = is_string($manifest['provider_key'] ?? null) ? $manifest['provider_key'] : $id;
        $name = is_string($manifest['name'] ?? null) ? $manifest['name'] : $id;
        $version = is_string($manifest['version'] ?? null) ? $manifest['version'] : '0.0.0';

        $prefixes = is_array($manifest['source_prefixes'] ?? null) ? array_values(array_filter($manifest['source_prefixes'], static fn(mixed $value): bool => is_string($value))) : [];
        $grants = is_array($manifest['http_grants'] ?? null) ? array_values(array_filter($manifest['http_grants'], 'is_array')) : [];

        return new self($id, $providerKey, $name, $version, $root, $prefixes, $grants, $options, $sourceFields, $credentials, $helper);
    }

    /** @param array<string, mixed> $source
     * @return array<string, bool|int|string>
     */
    public function normalizeSource(array $source): array
    {
        $fields = [];

        foreach ($this->sourceFields as $field) {
            $fields[$field->key] = $field;
        }

        foreach ($source as $key => $value) {
            if (! isset($fields[$key])) {
                throw new \InvalidArgumentException('Unknown source field.');
            }
        }

        $normalized = [];

        foreach ($fields as $key => $field) {
            if (! array_key_exists($key, $source)) {
                if ($field->required) {
                    throw new \InvalidArgumentException("Source field {$key} is required.");
                }

                continue;
            }
            $normalized[$key] = $field->normalize($source[$key]);
        }

        return $normalized;
    }

    /** @param array<mixed, mixed> $value
     * @return array<string, mixed>
     */
    private static function object(array $value): array
    {
        $object = [];

        foreach ($value as $key => $entry) {
            if (is_string($key)) {
                $object[$key] = $entry;
            }
        }

        return $object;
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
                $declared = $this->credential(self::scalarString($c['name'] ?? null, ''));
                $secretKey = $declared->secretKey ?? self::scalarString($c['secret_key'] ?? null, '');
                $value = $secretKey === '' ? null : $secrets->get($secretKey);

                if ($value !== null && $value !== '') {
                    $credential = new PluginCredentialGrant(self::scalarString($c['name'] ?? null, ''), $value, self::scalarString($c['parameter'] ?? null, 'key'), self::scalarString($c['placement'] ?? null, 'query'));
                }
            }
            $result[] = new PluginHttpGrant($prefixes, $credential);
        }

        return $result;
    }

    public function credential(string $key): ?PluginCredentialDefinition
    {
        foreach ($this->credentials as $credential) {
            if ($credential->key === $key) {
                return $credential;
            }
        }

        return null;
    }

    private static function scalarString(mixed $value, string $default): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }
}
