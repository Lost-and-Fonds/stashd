<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Package;

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
        if (! is_file($path)) {
            throw new PackageValidationError('plugin.json is missing');
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new PackageValidationError('plugin.json is invalid JSON', 0, $exception);
        }
        if (! is_array($data)) {
            throw new PackageValidationError('plugin.json must be an object');
        }
        foreach (['id', 'name', 'version', 'runtime', 'api_version', 'entrypoint'] as $key) {
            if (! isset($data[$key]) || ! is_string($data[$key]) || $data[$key] === '') {
                throw new PackageValidationError("plugin.json field {$key} is required");
            }
        }
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/', $data['id']) !== 1) {
            throw new PackageValidationError('plugin ID is invalid');
        }
        if (preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $data['version']) !== 1) {
            throw new PackageValidationError('plugin version is invalid');
        }
        if ($data['api_version'] !== $apiVersion) {
            throw new PackageValidationError('plugin API version is incompatible');
        }
        if ($data['runtime'] !== 'php') {
            throw new PackageValidationError('plugin runtime is unsupported');
        }
        self::validateRelative($data['entrypoint'], 'entrypoint');
        $requires = is_array($data['requires'] ?? null) ? $data['requires'] : [];
        $extensions = $requires['extensions'] ?? [];
        $architectures = $data['architectures'] ?? [];
        if (! is_array($extensions) || ! array_is_list($extensions) || ! is_array($architectures) || ! array_is_list($architectures)) {
            throw new PackageValidationError('manifest compatibility declarations are invalid');
        }
        $extensionNames = [];
        foreach ($extensions as $extension) {
            if (! is_string($extension) || preg_match('/^[a-zA-Z0-9_]+$/', $extension) !== 1 || ! extension_loaded($extension)) {
                throw new PackageValidationError('required PHP extension is unavailable');
            }
            $extensionNames[] = $extension;
        }
        $architectureNames = [];
        foreach ($architectures as $architectureName) {
            if (! is_string($architectureName)) {
                throw new PackageValidationError('manifest architecture is invalid');
            }
            $architectureNames[] = $architectureName;
        }
        if ($architectureNames !== [] && $architecture !== null && ! in_array($architecture, $architectureNames, true)) {
            throw new PackageValidationError('plugin architecture is unsupported');
        }
        $phpConstraint = is_string($requires['php'] ?? null) ? $requires['php'] : '>=8.5';
        if (! self::phpSatisfies($phpConstraint)) {
            throw new PackageValidationError('PHP version is incompatible');
        }

        return new self($data['id'], $data['name'], $data['version'], $data['runtime'], $data['api_version'], $data['entrypoint'], $phpConstraint, $extensionNames, $architectureNames);
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
        if (preg_match('/^>=\s*(\d+\.\d+)(?:\.\d+)?$/', $constraint, $match) === 1) {
            return version_compare(PHP_VERSION, $match[1], '>=');
        }

        return false;
    }
}
