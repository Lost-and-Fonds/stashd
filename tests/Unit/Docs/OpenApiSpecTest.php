<?php

declare(strict_types=1);

namespace Tests\Unit\Docs;

use Symfony\Component\Yaml\Yaml;

/**
 * Keeps docs/openapi.yaml (T17) honest as the API grows: parses as valid
 * YAML, has the OpenAPI document's required top-level keys, and -- the
 * part structural validity alone wouldn't catch -- every #[Get]/#[Post]/
 * #[Patch]/#[Delete] route actually registered under /api/v1 has a
 * matching `paths` entry in the spec. Scans source rather than hardcoding
 * a route list so it keeps catching drift as routes are added.
 */
function discoverApiV1RoutesFromSource(): array
{
    $appRoot = dirname(__DIR__, 3) . '/app';
    $routes = [];

    $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($appRoot, \FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (! preg_match_all(
            "/#\[(?:Get|Post|Patch|Delete|Put)\('(\/api\/v1\/[^']*)'/",
            $contents,
            $matches,
        )) {
            continue;
        }

        foreach ($matches[1] as $path) {
            $routes[$path] = true;
        }
    }

    return array_keys($routes);
}

test('openapi.yaml parses and has the required top-level OpenAPI keys', function (): void {
    $spec = Yaml::parseFile(dirname(__DIR__, 3) . '/docs/openapi.yaml');

    expect($spec)->toHaveKeys(['openapi', 'info', 'paths', 'components'])
        ->and($spec['openapi'])->toStartWith('3.')
        ->and($spec['paths'])->not->toBeEmpty();
});

test('every /api/v1 route registered in app/ has a matching openapi.yaml path entry', function (): void {
    $spec = Yaml::parseFile(dirname(__DIR__, 3) . '/docs/openapi.yaml');
    $documentedPaths = array_keys($spec['paths']);

    $missing = array_values(array_filter(
        discoverApiV1RoutesFromSource(),
        static fn(string $route): bool => ! in_array($route, $documentedPaths, true),
    ));

    expect($missing)->toBe([]);
});
