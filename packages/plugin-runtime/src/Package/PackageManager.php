<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Package;

use RuntimeException;

use function Tempest\Support\Filesystem\create_directory;
use function Tempest\Support\Filesystem\exists;
use function Tempest\Support\Filesystem\is_directory;
use function Tempest\Support\Filesystem\is_file;
use function Tempest\Support\Filesystem\list_directory;
use function Tempest\Support\Filesystem\read_file;

final class PackageManager
{
    private string $packages;

    private string $active;

    private string $links;

    private string $staging;

    public function __construct(private string $root, private string $apiVersion = '0.1', private ?string $architecture = null)
    {
        $this->packages = $root . '/packages';
        $this->active = $root . '/active';
        $this->links = $root . '/links';
        $this->staging = $root . '/staging';

        foreach ([$this->packages, $this->active, $this->links, $this->staging] as $directory) {
            try {
                create_directory($directory, 0700);
            } catch (\Throwable $exception) {
                throw new RuntimeException('package directory could not be created');
            }
        }
    }

    public function install(string $archive, string $expectedSha256): PackageManifest
    {
        if (! is_file($archive) || ! hash_equals(strtolower($expectedSha256), hash_file('sha256', $archive) ?: '')) {
            throw new PackageValidationError('package checksum mismatch');
        }
        $temporary = $this->staging . '/install-' . bin2hex(random_bytes(10));
        create_directory($temporary, 0700);

        try {
            TarArchive::extract($archive, $temporary);
            $manifest = PackageManifest::fromFile($this->manifestPath($temporary), $this->apiVersion, $this->architecture ?? self::architecture());
            $entrypoint = $temporary . '/' . $manifest->entrypoint;

            if (! is_file($entrypoint)) {
                throw new PackageValidationError('manifest entrypoint is missing');
            }
            $destination = $this->packages . '/' . $manifest->id . '/' . $manifest->version;

            if (exists($destination) || is_link($destination)) {
                throw new PackageStateError('plugin version is already installed');
            }
            $parent = dirname($destination);

            try {
                create_directory($parent, 0700);
            } catch (\Throwable $exception) {
                throw new PackageStateError('package version directory could not be created', 0, $exception);
            }

            if (! rename($temporary, $destination)) {
                throw new PackageStateError('package version could not be committed');
            }
            $this->makeImmutable($destination);

            return $manifest;
        } finally {
            if (is_dir($temporary)) {
                $this->removeTree($temporary);
            }
        }
    }

    /** Install a single-platform OCI image layout by its immutable manifest digest. */
    public function installOciLayout(string $layout, string $manifestDigest): PackageManifest
    {
        if (! preg_match('/^sha256:[a-f0-9]{64}$/', $manifestDigest)) {
            throw new PackageValidationError('OCI manifest digest is invalid');
        }

        try {
            $index = json_decode(read_file($layout . '/index.json'), true);
        } catch (\Throwable) {
            $index = null;
        }

        if (! is_array($index) || ! is_array($index['manifests'] ?? null)) {
            throw new PackageValidationError('OCI index is invalid');
        }
        $entry = null;

        foreach ($index['manifests'] as $candidate) {
            if (is_array($candidate) && ($candidate['digest'] ?? null) === $manifestDigest) {
                $entry = $candidate;

                break;
            }
        }

        if ($entry === null) {
            throw new PackageValidationError('OCI manifest is not present in index');
        }
        $platform = is_array($entry['platform'] ?? null) ? $entry['platform'] : [];
        $architecture = is_string($platform['architecture'] ?? null) ? $platform['architecture'] : null;
        $platformOs = is_string($platform['os'] ?? null) ? $platform['os'] : 'linux';

        if ($platformOs !== 'linux' || ($architecture !== null && $architecture !== self::architecture())) {
            throw new PackageValidationError('OCI plugin platform is incompatible');
        }
        $manifestPath = $layout . '/blobs/sha256/' . substr($manifestDigest, 7);

        if (! is_file($manifestPath) || ! hash_equals(substr($manifestDigest, 7), hash_file('sha256', $manifestPath) ?: '')) {
            throw new PackageValidationError('OCI manifest checksum mismatch');
        }

        try {
            $manifest = json_decode(read_file($manifestPath), true);
        } catch (\Throwable) {
            $manifest = null;
        }

        if (! is_array($manifest) || ! is_array($manifest['layers'] ?? null) || count($manifest['layers']) !== 1) {
            throw new PackageValidationError('OCI plugin manifest must contain one layer');
        }
        $layer = $manifest['layers'][0];
        $digest = is_array($layer) && is_string($layer['digest'] ?? null) ? $layer['digest'] : '';

        if (! preg_match('/^sha256:[a-f0-9]{64}$/', $digest)) {
            throw new PackageValidationError('OCI layer digest is invalid');
        }
        $archive = $layout . '/blobs/sha256/' . substr($digest, 7);

        return $this->install($archive, substr($digest, 7));
    }

    public function activate(string $id, string $version): void
    {
        $this->validateId($id);
        $this->validateVersion($version);
        $package = $this->packages . '/' . $id . '/' . $version;

        if (! is_dir($package) || ! is_file($this->manifestPath($package))) {
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

        if (is_link($path) || is_file($path)) {
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

        if (is_directory($path)) {
            $this->makeMutable($path);
            $this->removeTree($path);
        }
    }

    public function link(string $id, string $source): PackageManifest
    {
        $this->validateId($id);
        $source = realpath($source) ?: throw new PackageValidationError('linked source does not exist');
        $manifest = PackageManifest::fromFile($this->manifestPath($source), $this->apiVersion, $this->architecture ?? self::architecture());

        if (! is_file($source . '/' . $manifest->entrypoint)) {
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

        if (! is_file($manifest)) {
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

    private function validateId(string $id): void
    {
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/', $id) !== 1) {
            throw new PackageStateError('plugin ID is invalid');
        }
    }

    private function manifestPath(string $package): string
    {
        $root = rtrim($package, '/');

        return is_file($root . '/plugin.json') ? $root . '/plugin.json' : $root . '/stashd-plugin/plugin.json';
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

        if (is_directory($path)) {
            chmod($path, $directoryMode);

            foreach (list_directory($path) as $entry) {
                $this->walkMode($entry, $fileMode, $directoryMode);
            }

            return;
        }
        chmod($path, $fileMode);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_directory($path)) {
            return;
        }

        foreach (list_directory($path) as $entry) {
            $this->removeTree($entry);
        }
        @rmdir($path);
    }
}
