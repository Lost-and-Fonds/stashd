<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Package;

use RuntimeException;
use Tempest\DateTime\Duration;
use Tempest\Process\GenericProcessExecutor;
use Tempest\Process\PendingProcess;
use Tempest\Support\Filesystem;

/** Materializes a declared plugin and its locked helper inputs into an OCI layout. */
final class PluginBuilder
{
    private Umoci $umoci;

    public function __construct(private string $store, ?Umoci $umoci = null)
    {
        try {
            Filesystem\create_directory($store, 0700);
        } catch (\Throwable $exception) {
            throw new RuntimeException('plugin build store could not be created', 0, $exception);
        }
        $this->umoci = $umoci ?? new Umoci();
    }

    /** @return array{layout: string, digest: string, platform: string, reused: bool} */
    public function materialize(string $source, ?string $platform = null): array
    {
        $source = realpath($source) ?: throw new PackageValidationError('plugin source does not exist');
        $platform ??= self::platform();

        if (! in_array($platform, ['linux-amd64', 'linux-arm64'], true)) {
            throw new PackageValidationError('unsupported plugin platform: ' . $platform);
        }
        $manifestPath = $source . '/stashd-plugin/plugin.json';
        $lockPath = $source . '/stashd-plugin/helpers.lock.json';
        $manifest = $this->jsonFile($manifestPath);
        $lock = $this->jsonFile($lockPath);
        $composerLock = Filesystem\is_file($source . '/composer.lock') ? Filesystem\read_file($source . '/composer.lock') : '';
        $input = hash('sha256', Filesystem\read_file($manifestPath) . Filesystem\read_file($lockPath) . $composerLock . $platform);
        $layout = $this->store . '/' . $input;

        if (Filesystem\is_file($layout . '/index.json')) {
            $digest = $this->umoci->manifestDigest($layout, 'stashd');

            return ['layout' => $layout, 'digest' => $digest, 'platform' => $platform, 'reused' => true];
        }
        $temporary = $this->store . '/.build-' . bin2hex(random_bytes(8));
        $bundle = $this->store . '/.bundle-' . bin2hex(random_bytes(8));
        Filesystem\create_directory($temporary, 0700);

        try {
            $this->copyTree($source, $temporary);
            $this->remove($temporary . '/.git');
            $this->remove($temporary . '/tests');
            $this->remove($temporary . '/tools');
            $this->remove($temporary . '/vendor');
            $this->installComposer($temporary);
            $this->materializeHelpers($temporary, $manifest, $lock, $platform);
            $buildLayout = $layout . '.build';
            $this->remove($buildLayout);
            $this->umoci->init($buildLayout);
            $this->umoci->newImage($buildLayout, 'stashd');
            $this->umoci->unpack($buildLayout, 'stashd', $bundle);
            $this->copyTree($temporary, $bundle . '/rootfs');
            $environment = ['SOURCE_DATE_EPOCH' => '0'];
            $this->umoci->repack($buildLayout, 'stashd', $bundle, $environment);
            $this->umoci->configure($buildLayout, 'stashd', $platform, $environment);
            $result = $this->umoci->manifestDigest($buildLayout, 'stashd');

            if (! preg_match('/^sha256:[a-f0-9]{64}$/', $result) || ! rename($buildLayout, $layout)) {
                throw new PackageValidationError('plugin OCI layout could not be committed');
            }

            return ['layout' => $layout, 'digest' => $result, 'platform' => $platform, 'reused' => false];
        } finally {
            $this->remove($temporary);
            $this->remove($bundle);
        }
    }

    private function installComposer(string $root): void
    {
        if (! Filesystem\is_file($root . '/composer.lock')) {
            throw new PackageValidationError('plugin composer.lock is required for materialization');
        }
        $composer = getenv('COMPOSER_BINARY') ?: 'composer';
        $command = [$composer, 'install', '--working-dir=' . $root, '--no-dev', '--no-scripts', '--no-plugins', '--no-interaction', '--prefer-dist', '--optimize-autoloader'];
        $environment = getenv();
        $environment['COMPOSER_VENDOR_DIR'] = $root . '/vendor';
        $result = (new GenericProcessExecutor())->run(new PendingProcess($command, Duration::seconds(300), environment: $environment));
        $error = $result->errorOutput;
        $output = $result->output;
        $exit = $result->exitCode;

        if ($exit !== 0) {
            throw new PackageValidationError('locked Composer install failed: ' . trim((string) $error . ' ' . (string) $output));
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $lock
     */
    private function materializeHelpers(string $root, array $manifest, array $lock, string $platform): void
    {
        $declared = is_array($manifest['helpers'] ?? null) ? $manifest['helpers'] : [];
        $locked = is_array($lock['helpers'] ?? null) ? $lock['helpers'] : [];

        foreach ($locked as $name => $input) {
            if (! is_string($name) || ! is_array($input) || ! is_array($declared[$name] ?? null)) {
                throw new PackageValidationError('helper lock does not match the plugin manifest');
            }
            $platforms = $input['platforms'] ?? null;

            if (! is_array($platforms)) {
                throw new PackageValidationError('helper lock does not match the plugin manifest');
            }
            $artifact = $platforms[$platform] ?? null;

            if (! is_array($artifact) || ! is_string($artifact['url'] ?? null) || ! is_string($artifact['sha256'] ?? null)) {
                throw new PackageValidationError('helper has no locked artifact for ' . $platform);
            }
            $target = $declared[$name]['executable'] ?? null;

            if (! is_string($target) || str_starts_with($target, '/') || str_contains($target, '..')) {
                throw new PackageValidationError('helper executable path is unsafe');
            }
            $download = $root . '/.helper-' . bin2hex(random_bytes(6));

            if (@copy($artifact['url'], $download) === false || ! hash_equals(strtolower($artifact['sha256']), hash_file('sha256', $download) ?: '')) {
                throw new PackageValidationError('helper checksum verification failed for ' . $name);
            }
            $destination = $root . '/' . $target;

            Filesystem\create_directory(dirname($destination), 0755);

            if (is_string($artifact['archive_binary'] ?? null)) {
                $extract = $root . '/.helper-extract-' . bin2hex(random_bytes(4));
                Filesystem\create_directory($extract, 0700);
                $command = ['tar', '-xJf', $download, '-C', $extract, $artifact['archive_binary']];
                $result = (new GenericProcessExecutor())->run(new PendingProcess($command, Duration::seconds(60)));
                $error = $result->errorOutput;
                $exit = $result->exitCode;
                $extracted = $extract . '/' . $artifact['archive_binary'];

                if ($exit !== 0 || ! Filesystem\is_file($extracted)) {
                    throw new PackageValidationError('helper archive extraction failed: ' . trim((string) $error));
                }
                Filesystem\copy($extracted, $destination, overwrite: true);
                $this->remove($extract);
            } else {
                Filesystem\copy($download, $destination, overwrite: true);
            }
            chmod($destination, 0555);
            unlink($download);
        }
    }

    /** @return array<string, mixed> */
    private function jsonFile(string $path): array
    {
        try {
            $value = Filesystem\read_json($path);
        } catch (\Throwable) {
            $value = null;
        }

        if (! is_array($value)) {
            throw new PackageValidationError('invalid plugin declaration: ' . $path);
        }
        $result = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new PackageValidationError('invalid plugin declaration: ' . $path);
            }
            $result[$key] = $item;
        }

        return $result;
    }

    private function copyTree(string $source, string $destination): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            if (! $item instanceof \SplFileInfo) {
                continue;
            }
            $target = $destination . '/' . substr($item->getPathname(), strlen($source) + 1);

            if ($item->isLink()) {
                Filesystem\create_directory(dirname($target), 0755);
                symlink(readlink($item->getPathname()) ?: throw new PackageValidationError('plugin symlink target is invalid'), $target);
            } elseif ($item->isDir()) {
                Filesystem\create_directory($target, 0755);
                chmod($target, $item->getPerms() & 0777);
            } else {
                Filesystem\create_directory(dirname($target), 0755);
                Filesystem\copy($item->getPathname(), $target, overwrite: true);
                chmod($target, $item->getPerms() & 0777);
            }
        }
    }

    private function remove(string $path): void
    {
        if (! Filesystem\exists($path) && ! Filesystem\is_symbolic_link($path)) {
            return;
        }

        if (Filesystem\is_directory($path) && ! Filesystem\is_symbolic_link($path)) {
            foreach (Filesystem\list_directory($path) as $entry) {
                $this->remove($entry);
            }
        }

        if (Filesystem\is_directory($path) && ! Filesystem\is_symbolic_link($path)) {
            Filesystem\delete_directory($path, recursive: false);

            return;
        }

        Filesystem\delete_file($path);
    }

    private static function platform(): string
    {
        return match (php_uname('m')) {
            'x86_64', 'amd64' => 'linux-amd64', 'aarch64', 'arm64' => 'linux-arm64', default => throw new PackageValidationError('unsupported host architecture'),
        };
    }
}
