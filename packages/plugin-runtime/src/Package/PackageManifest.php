<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Package;

use Composer\Semver\Semver;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;
use Tempest\Support\Filesystem;

final readonly class PackageManifest
{
    /**
     * @param  list<string>  $extensions
     * @param  list<string>  $architectures
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public string $runtime,
        public string $apiVersion,
        public string $entrypoint,
        public string $phpConstraint = '>=8.5',
        public array $extensions = [],
        public array $architectures = [],
    ) {}

    public static function fromFile(string $path, string $apiVersion = '0.1', ?string $architecture = null): self
    {
        if (! Filesystem\is_file($path)) {
            throw new PackageValidationError('plugin.json is missing');
        }

        try {
            $data = json_decode(Filesystem\read_file($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new PackageValidationError('plugin.json is invalid JSON', 0, $exception);
        }

        if (! is_array($data)) {
            throw new PackageValidationError('plugin.json must be an object');
        }

        $data = self::object($data);
        self::validateData($data);

        if ($data['api_version'] !== $apiVersion) {
            throw new PackageValidationError('plugin API version is incompatible');
        }

        if ($data['runtime'] !== 'php') {
            throw new PackageValidationError('plugin runtime is unsupported');
        }
        self::validateRelative(self::string($data['entrypoint']), 'entrypoint');
        $requires = self::object($data['requires'] ?? []);
        $extensions = self::stringList($requires['extensions'] ?? []);
        $architectures = self::stringList($data['architectures'] ?? []);
        $extensionNames = [];

        foreach ($extensions as $extension) {
            if (! extension_loaded($extension)) {
                throw new PackageValidationError('required PHP extension is unavailable');
            }
            $extensionNames[] = $extension;
        }
        $architectureNames = [];

        foreach ($architectures as $architectureName) {
            $architectureNames[] = $architectureName;
        }

        if ($architectureNames !== [] && $architecture !== null && ! in_array($architecture, $architectureNames, true)) {
            throw new PackageValidationError('plugin architecture is unsupported');
        }
        $phpConstraint = is_string($requires['php'] ?? null) ? $requires['php'] : '>=8.5';

        if (! self::phpSatisfies($phpConstraint)) {
            throw new PackageValidationError('PHP version is incompatible');
        }

        return new self(self::string($data['id']), self::string($data['name']), self::string($data['version']), self::string($data['runtime']), self::string($data['api_version']), self::string($data['entrypoint']), $phpConstraint, $extensionNames, $architectureNames);
    }

    /** @param array<string, mixed> $data */
    public static function validateData(array $data): void
    {
        $schemaPath = dirname(__DIR__, 2) . '/resources/plugin-manifest-v0.1.schema.json';
        $schema = json_decode(Filesystem\read_file($schemaPath), flags: JSON_THROW_ON_ERROR);

        if (! is_object($schema)) {
            throw new PackageValidationError('plugin manifest schema is invalid');
        }
        $validator = new Validator();
        $validator->setMaxErrors(20);
        $validator->setStopAtFirstError(false);
        $result = $validator->validate(Helper::toJSON($data), $schema);

        if ($result->isValid()) {
            return;
        }

        $error = $result->error();

        if ($error === null) {
            throw new PackageValidationError('plugin.json structural validation failed');
        }

        $errors = (new ErrorFormatter())->format($error);
        $messages = [];

        foreach ($errors as $path => $pathErrors) {
            if (is_array($pathErrors)) {
                $messages[] = sprintf('%s: %s', $path, implode('; ', array_map(static fn(mixed $message): string => is_string($message) ? $message : get_debug_type($message), $pathErrors)));
            }
        }

        throw new PackageValidationError('plugin.json structural validation failed: ' . implode(' | ', $messages));
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value): array
    {
        if (! is_array($value)) {
            throw new PackageValidationError('plugin.json object value is invalid');
        }

        $object = [];

        foreach ($value as $key => $entry) {
            if (! is_string($key)) {
                throw new PackageValidationError('plugin.json object key is invalid');
            }

            $object[$key] = $entry;
        }

        return $object;
    }

    private static function string(mixed $value): string
    {
        if (! is_string($value)) {
            throw new PackageValidationError('plugin.json string value is invalid');
        }

        return $value;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            throw new PackageValidationError('plugin.json string list is invalid');
        }

        $list = [];

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                throw new PackageValidationError('plugin.json string list entry is invalid');
            }

            $list[] = $entry;
        }

        return $list;
    }

    private static function validateRelative(string $path, string $label): void
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new PackageValidationError("{$label} must be relative");
        }
        $parts = explode('/', $path);

        if (in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) {
            throw new PackageValidationError("{$label} is unsafe");
        }
    }

    private static function phpSatisfies(string $constraint): bool
    {
        try {
            return Semver::satisfies(PHP_VERSION, $constraint);
        } catch (\UnexpectedValueException) {
            return false;
        }
    }
}
