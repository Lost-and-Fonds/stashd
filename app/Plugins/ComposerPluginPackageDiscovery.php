<?php

declare(strict_types=1);

namespace App\Plugins;

use Composer\InstalledVersions;
use RuntimeException;
use Stashd\PluginRuntime\Package\PackageManifest;
use Tempest\Support\Filesystem;

final class ComposerPluginPackageDiscovery
{
    /** @return list<array{name: string, root: string, manifest: array<string, mixed>, manifest_path: string}> */
    public function all(?string $activeRoot = null): array
    {
        $packages = [];

        foreach (InstalledVersions::getInstalledPackagesByType('stashd-plugin') as $name) {
            $root = realpath(InstalledVersions::getInstallPath($name));

            if ($root === false || trim($root) === '') {
                continue;
            }
            $composerPath = rtrim($root, '/') . '/composer.json';
            $composer = $this->readJson($composerPath);
            $payload = $this->pluginPayload($composer);
            $relative = is_string($payload['manifest'] ?? null) ? trim($payload['manifest']) : '';

            if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
                throw new RuntimeException("Invalid Stashd plugin manifest declaration in {$name}.");
            }
            $manifestPath = $root . '/' . $relative;
            $manifest = $this->readJson($manifestPath);

            if (! is_array($manifest)) {
                throw new RuntimeException("Invalid Stashd plugin manifest: {$manifestPath}");
            }
            PackageManifest::validateData($manifest);
            $packages[] = ['name' => $name, 'root' => $root, 'manifest' => $manifest, 'manifest_path' => $manifestPath];
        }

        if ($activeRoot !== null && Filesystem\is_directory($activeRoot)) {
            foreach (Filesystem\list_directory($activeRoot) as $root) {
                if (! Filesystem\is_directory($root)) {
                    continue;
                }
                $real = realpath($root);

                if ($real === false || in_array($real, array_column($packages, 'root'), true)) {
                    continue;
                }
                $composer = $this->readJson($real . '/composer.json');
                $payload = $this->pluginPayload($composer);
                $relative = is_string($payload['manifest'] ?? null) ? trim($payload['manifest']) : 'stashd-plugin/plugin.json';

                if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
                    throw new RuntimeException('Invalid installed plugin manifest declaration.');
                }
                $manifestPath = $real . '/' . $relative;
                $manifest = $this->readJson($manifestPath);

                if (is_array($manifest)) {
                    PackageManifest::validateData($manifest);
                    $name = is_string($composer['name'] ?? null)
                        ? $composer['name']
                        : (is_string($manifest['id'] ?? null) ? $manifest['id'] : 'installed');
                    $packages[] = ['name' => $name, 'root' => $real, 'manifest' => $manifest, 'manifest_path' => $manifestPath];
                }
            }

            $activeIds = array_values(array_filter(array_map(
                static fn(array $package): ?string => is_string($package['manifest']['id'] ?? null) ? $package['manifest']['id'] : null,
                array_filter($packages, static fn(array $package): bool => str_starts_with($package['root'], rtrim($activeRoot, '/') . '/')),
            )));

            if ($activeIds !== []) {
                $packages = array_values(array_filter(
                    $packages,
                    static fn(array $package): bool => ! in_array($package['manifest']['id'] ?? null, $activeIds, true)
                        || str_starts_with($package['root'], rtrim($activeRoot, '/') . '/'),
                ));
            }
        }

        return $packages;
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $path): ?array
    {
        try {
            $value = json_decode(Filesystem\read_file($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($value)) {
            return null;
        }

        $object = [];

        foreach ($value as $key => $entry) {
            if (! is_string($key)) {
                return null;
            }
            $object[$key] = $entry;
        }

        return $object;
    }

    /**
     * @param  array<string, mixed>|null  $composer
     * @return array<string, mixed>
     */
    private function pluginPayload(?array $composer): array
    {
        $extra = $composer['extra'] ?? null;
        $payload = is_array($extra) ? ($extra['stashd-plugin'] ?? null) : null;

        if (! is_array($payload)) {
            return [];
        }

        $result = [];

        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                return [];
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
