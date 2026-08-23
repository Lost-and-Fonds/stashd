<?php

declare(strict_types=1);

namespace App\Plugins;

use Composer\InstalledVersions;
use RuntimeException;

final class ComposerPluginPackageDiscovery
{
    /** @return list<array{name: string, root: string, manifest: array<string, mixed>, manifest_path: string}> */
    public function all(?string $activeRoot = null): array
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

        if ($activeRoot !== null && is_dir($activeRoot)) {
            foreach (glob(rtrim($activeRoot, '/') . '/*', GLOB_ONLYDIR) ?: [] as $root) {
                $real = realpath($root);
                if ($real === false || in_array($real, array_column($packages, 'root'), true)) {
                    continue;
                }
                $composer = json_decode((string) @file_get_contents($real . '/composer.json'), true);
                $payload = is_array($composer) && is_array($composer['extra']['stashd-plugin'] ?? null) ? $composer['extra']['stashd-plugin'] : [];
                $relative = is_string($payload['manifest'] ?? null) ? trim($payload['manifest']) : 'stashd-plugin/plugin.json';
                if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
                    throw new RuntimeException('Invalid installed plugin manifest declaration.');
                }
                $manifestPath = $real . '/' . $relative;
                $manifest = json_decode((string) @file_get_contents($manifestPath), true);
                if (is_array($manifest)) {
                    $packages[] = ['name' => is_string($composer['name'] ?? null) ? $composer['name'] : (string) ($manifest['id'] ?? 'installed'), 'root' => $real, 'manifest' => $manifest, 'manifest_path' => $manifestPath];
                }
            }
        }

        return $packages;
    }
}
