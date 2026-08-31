<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Package;

use RuntimeException;
use Tempest\Support\Filesystem;

final class PackageManager
{
    private string $packages;

    private string $active;

    private string $links;

    private string $staging;

    private Umoci $umoci;

    public function __construct(private string $root, private string $apiVersion = '0.1', private ?string $architecture = null, ?Umoci $umoci = null)
    {
        $this->packages = $root . '/packages';
        $this->active = $root . '/active';
        $this->links = $root . '/links';
        $this->staging = $root . '/staging';

        foreach ([$this->root, $this->packages, $this->active, $this->links] as $directory) {
            try {
                Filesystem\create_directory($directory, 0755);
                chmod($directory, 0755);
            } catch (\Throwable $exception) {
                throw new RuntimeException('package directory could not be created');
            }
        }
        try {
            Filesystem\create_directory($this->staging, 0700);
        } catch (\Throwable $exception) {
            throw new RuntimeException('package staging directory could not be created');
        }
        $this->umoci = $umoci ?? new Umoci();
    }

    /** Install a single-platform OCI image layout by its immutable manifest digest. */
    public function installOciLayout(string $layout, string $manifestDigest, ?string $reference = null): PackageManifest
    {
        if (! preg_match('/^sha256:[a-f0-9]{64}$/', $manifestDigest)) {
            throw new PackageValidationError('OCI manifest digest is invalid');
        }

        $stat = $this->umoci->stat($layout, 'stashd');
        $manifestMetadata = $stat['manifest'] ?? null;
        $descriptor = is_array($manifestMetadata) ? ($manifestMetadata['descriptor'] ?? null) : null;
        $actualDigest = is_array($descriptor) ? ($descriptor['digest'] ?? null) : null;

        if (! is_string($actualDigest) || ! hash_equals($manifestDigest, $actualDigest)) {
            throw new PackageValidationError('OCI manifest digest does not match the requested package');
        }
        $configMetadata = is_array($stat['config'] ?? null) ? $stat['config'] : [];
        $config = is_array($configMetadata['blob'] ?? null) ? $configMetadata['blob'] : [];
        $platformOs = is_string($config['os'] ?? null) ? $config['os'] : null;
        $architecture = is_string($config['architecture'] ?? null) ? $config['architecture'] : null;

        if ($platformOs !== 'linux' || $architecture !== ($this->architecture ?? self::architecture())) {
            throw new PackageValidationError('OCI plugin platform is incompatible');
        }
        $temporary = $this->staging . '/install-' . bin2hex(random_bytes(10));
        Filesystem\create_directory($temporary, 0700);

        try {
            $bundle = $temporary . '/bundle';
            $this->umoci->unpack($layout, 'stashd', $bundle);
            $rootfs = $bundle . '/rootfs';
            $manifest = PackageManifest::fromFile($this->manifestPath($rootfs), $this->apiVersion, $this->architecture ?? self::architecture());
            $entrypoint = $rootfs . '/' . $manifest->entrypoint;

            if (! Filesystem\is_file($entrypoint)) {
                throw new PackageValidationError('manifest entrypoint is missing');
            }
            $destination = $this->packages . '/' . $manifest->id . '/' . $manifest->version;

            if (Filesystem\exists($destination) || is_link($destination)) {
                $installed = $this->installedMetadata($destination);

                if (($installed['digest'] ?? null) === $manifestDigest) {
                    return $manifest;
                }

                throw new PackageStateError('a different plugin artifact already uses this version');
            }
            Filesystem\create_directory(dirname($destination), 0755);
            chmod(dirname($destination), 0755);

            if (! rename($rootfs, $destination)) {
                throw new PackageStateError('package version could not be committed');
            }
            file_put_contents($destination . '/install.json', json_encode([
                'reference' => $reference,
                'digest' => $manifestDigest,
                'installed_at' => gmdate(DATE_ATOM),
            ], JSON_THROW_ON_ERROR));
            $this->makeImmutable($destination);

            return $manifest;
        } finally {
            if (Filesystem\is_directory($temporary)) {
                $this->removeTree($temporary);
            }
        }
    }

    public function activate(string $id, string $version): void
    {
        $this->validateId($id);
        $this->validateVersion($version);
        $package = $this->packages . '/' . $id . '/' . $version;

        if (! is_dir($package) || ! Filesystem\is_file($this->manifestPath($package))) {
            throw new PackageStateError('plugin version is not installed');
        }
        $current = $this->active . '/' . $id;
        $temporary = $this->active . '/.' . $id . '-' . bin2hex(random_bytes(8));

        if (! symlink('../packages/' . $id . '/' . $version, $temporary)) {
            throw new PackageStateError('active version link could not be prepared');
        }

        if (! rename($temporary, $current)) {
            @unlink($temporary);

            throw new PackageStateError('active version switch failed');
        }
    }

    public function rollback(string $id, string $version): void
    {
        $this->activate($id, $version);
    }

    public function disable(string $id): void
    {
        $this->validateId($id);
        $path = $this->active . '/' . $id;

        if (is_link($path) || Filesystem\is_file($path)) {
            unlink($path);
        }
    }

    public function remove(string $id, string $version): void
    {
        $this->validateId($id);
        $this->validateVersion($version);

        if ($this->activeVersion($id) === $version) {
            throw new PackageStateError('active plugin version must be disabled before removal');
        }
        $path = $this->packages . '/' . $id . '/' . $version;

        if (Filesystem\is_directory($path)) {
            $this->makeMutable($path);
            $this->removeTree($path);
        }
    }

    public function link(string $id, string $source): PackageManifest
    {
        $this->validateId($id);
        $source = realpath($source) ?: throw new PackageValidationError('linked source does not exist');
        $manifest = PackageManifest::fromFile($this->manifestPath($source), $this->apiVersion, $this->architecture ?? self::architecture());

        if (! Filesystem\is_file($source . '/' . $manifest->entrypoint)) {
            throw new PackageValidationError('linked entrypoint is missing');
        }
        $this->disable($id);
        $link = $this->links . '/' . $id;

        if (is_link($link) || file_exists($link)) {
            $this->removeTree($link);
        }

        if (! symlink($source, $link)) {
            throw new PackageStateError('development link could not be created');
        }
        $temporary = $this->active . '/.' . $id . '-link-' . bin2hex(random_bytes(8));

        if (! symlink('../links/' . $id, $temporary) || ! rename($temporary, $this->active . '/' . $id)) {
            @unlink($temporary);

            throw new PackageStateError('development link could not be activated');
        }

        return $manifest;
    }

    public function unlink(string $id): void
    {
        $this->validateId($id);
        $this->disable($id);
        $path = $this->links . '/' . $id;

        if (is_link($path)) {
            unlink($path);
        }
    }

    public function activeVersion(string $id): ?string
    {
        $this->validateId($id);
        $path = $this->active . '/' . $id;

        if (! is_link($path)) {
            return null;
        }
        $manifest = $this->manifestPath(realpath($path) ?: $path);

        if (! Filesystem\is_file($manifest)) {
            return null;
        }

        try {
            return PackageManifest::fromFile($manifest, $this->apiVersion, $this->architecture ?? self::architecture())->version;
        } catch (PackageValidationError) {
            return null;
        }
    }

    public function activePath(string $id): ?string
    {
        $this->validateId($id);
        $path = $this->active . '/' . $id;

        return is_link($path) ? (realpath($path) ?: null) : null;
    }

    public function activeRoot(): string
    {
        return $this->active;
    }

    /** @return list<array{id: string, version: string, runtime: string, api_version: string, reference: ?string, digest: ?string}> */
    public function installed(): array
    {
        $plugins = [];

        foreach (Filesystem\list_directory($this->active) as $path) {
            $root = realpath($path);

            if ($root === false) {
                continue;
            }

            try {
                $manifest = PackageManifest::fromFile($this->manifestPath($root), $this->apiVersion, $this->architecture ?? self::architecture());
            } catch (PackageValidationError) {
                continue;
            }
            $metadata = $this->installedMetadata($root);
            $plugins[] = [
                'id' => $manifest->id,
                'version' => $manifest->version,
                'runtime' => $manifest->runtime,
                'api_version' => $manifest->apiVersion,
                'reference' => is_string($metadata['reference'] ?? null) ? $metadata['reference'] : null,
                'digest' => is_string($metadata['digest'] ?? null) ? $metadata['digest'] : null,
            ];
        }

        usort($plugins, static fn(array $left, array $right): int => $left['id'] <=> $right['id']);

        return $plugins;
    }

    /** @return array<string, mixed> */
    private function installedMetadata(string $path): array
    {
        try {
            $metadata = json_decode(Filesystem\read_file($path . '/install.json'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($metadata) ? $metadata : [];
    }

    private function validateId(string $id): void
    {
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/', $id) !== 1) {
            throw new PackageStateError('plugin ID is invalid');
        }
    }

    private function manifestPath(string $package): string
    {
        $root = rtrim($package, '/');

        return Filesystem\is_file($root . '/plugin.json') ? $root . '/plugin.json' : $root . '/stashd-plugin/plugin.json';
    }

    private function validateVersion(string $version): void
    {
        if (preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) !== 1) {
            throw new PackageStateError('plugin version is invalid');
        }
    }

    private static function architecture(): string
    {
        return match (php_uname('m')) {
            'x86_64', 'amd64' => 'amd64', 'aarch64', 'arm64' => 'arm64', default => php_uname('m'),
        };
    }

    private function makeImmutable(string $path): void
    {
        $this->walkMode($path, 0444, 0555);
    }

    private function makeMutable(string $path): void
    {
        $this->walkMode($path, 0644, 0755);
    }

    private function walkMode(string $path, int $fileMode, int $directoryMode): void
    {
        if (is_link($path)) {
            return;
        }

        if (Filesystem\is_directory($path)) {
            chmod($path, $directoryMode);

            foreach (Filesystem\list_directory($path) as $entry) {
                $this->walkMode($entry, $fileMode, $directoryMode);
            }

            return;
        }
        $mode = (fileperms($path) & 0111) !== 0 ? $fileMode | 0111 : $fileMode;
        chmod($path, $mode);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || Filesystem\is_file($path)) {
            @unlink($path);

            return;
        }

        if (! Filesystem\is_directory($path)) {
            return;
        }

        foreach (Filesystem\list_directory($path) as $entry) {
            $this->removeTree($entry);
        }
        @rmdir($path);
    }
}
