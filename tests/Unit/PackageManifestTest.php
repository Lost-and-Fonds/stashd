<?php

declare(strict_types=1);

use Stashd\PluginRuntime\Package\PackageManifest;
use Stashd\PluginRuntime\Package\PackageValidationError;

function validPluginManifest(): array
{
    return [
        'id' => 'example',
        'name' => 'Example',
        'version' => '1.2.3',
        'runtime' => 'php',
        'api_version' => '0.1',
        'entrypoint' => 'stashd-plugin/plugin.php',
        'helpers' => ['helper' => ['executable' => 'stashd-plugin/helpers/helper', 'network' => false]],
        'requires' => ['php' => '>=8.5', 'extensions' => []],
        'architectures' => ['amd64', 'arm64'],
    ];
}

it('accepts a valid manifest and current provider manifests', function (): void {
    PackageManifest::validateData(validPluginManifest());

    foreach (glob(__DIR__ . '/../../../plugins/*/stashd-plugin/plugin.json') as $path) {
        PackageManifest::validateData(json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
    }

    expect(true)->toBeTrue();
});

it('reports the path for missing or wrongly typed structural fields', function (array $manifest, string $path): void {
    expect(fn(): mixed => PackageManifest::validateData($manifest))
        ->toThrow(PackageValidationError::class, $path);
})->with([
    'missing id' => [array_diff_key(validPluginManifest(), ['id' => true]), 'id'],
    'wrong id type' => [array_replace(validPluginManifest(), ['id' => 12]), '/id'],
    'malformed helper' => [array_replace(validPluginManifest(), ['helpers' => ['helper' => ['executable' => false]]]), '/helpers/helper/executable'],
    'invalid credential placement' => [array_replace(validPluginManifest(), ['http_grants' => [['allowed_prefixes' => ['https://example.test/'], 'credential' => ['name' => 'token', 'parameter' => 'key', 'placement' => 'cookie']]]]), '/http_grants/0/credential/placement'],
]);

it('keeps path containment and PHP compatibility as semantic checks', function (string $field, mixed $value, string $message): void {
    $path = tempnam(sys_get_temp_dir(), 'stashd-manifest-');
    file_put_contents($path, json_encode(array_replace(validPluginManifest(), [$field => $value]), JSON_THROW_ON_ERROR));

    try {
        expect(fn(): mixed => PackageManifest::fromFile($path))->toThrow(PackageValidationError::class, $message);
    } finally {
        unlink($path);
    }
})->with([
    'unsafe entrypoint' => ['entrypoint', '../plugin.php', 'entrypoint is unsafe'],
    'incompatible PHP' => ['requires', ['php' => '>=99.0', 'extensions' => []], 'PHP version is incompatible'],
]);
