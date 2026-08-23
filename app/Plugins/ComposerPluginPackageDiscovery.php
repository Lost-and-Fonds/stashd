<?php

declare(strict_types=1);

namespace App\Plugins;

use Composer\InstalledVersions;
use RuntimeException;

final class ComposerPluginPackageDiscovery
{
    /** @return list<array{name: string, root: string, manifest: array<string, mixed>, manifest_path: string}> */
    public function all(): array
    {
        $packages = [];
        foreach (InstalledVersions::getInstalledPackagesByType('stashd-plugin') as $name) {
            $root = InstalledVersions::getInstallPath($name);
            if (! is_string($root) || trim($root) === '') {
                continue;
            }
            $composerPath = rtrim($root, '/') . '/composer.json';
            $composer = json_decode((string) file_get_contents($composerPath), true);
            $payload = is_array($composer) && is_array($composer['extra']['stashd-plugin'] ?? null)
                ? $composer['extra']['stashd-plugin']
                : [];
            $relative = is_string($payload['manifest'] ?? null) ? trim($payload['manifest']) : '';
            if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
                throw new RuntimeException("Invalid Stashd plugin manifest declaration in {$name}.");
            }
            $manifestPath = $root . '/' . $relative;
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (! is_array($manifest)) {
                throw new RuntimeException("Invalid Stashd plugin manifest: {$manifestPath}");
            }
            $packages[] = ['name' => $name, 'root' => $root, 'manifest' => $manifest, 'manifest_path' => $manifestPath];
        }

        return $packages;
    }
}
