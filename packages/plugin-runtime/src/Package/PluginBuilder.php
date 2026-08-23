<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Package;

use RuntimeException;

/** Materializes a declared plugin and its locked helper inputs into an OCI layout. */
final class PluginBuilder
{
    public function __construct(private string $store)
    {
        if (! is_dir($store) && ! mkdir($store, 0700, true) && ! is_dir($store)) {
            throw new RuntimeException('plugin build store could not be created');
        }
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
        $input = hash('sha256', (string) file_get_contents($manifestPath) . (string) file_get_contents($lockPath) . (string) @file_get_contents($source . '/composer.lock') . $platform);
        $layout = $this->store . '/' . $input;

        if (is_file($layout . '/index.json')) {
            $index = $this->jsonFile($layout . '/index.json');
            $digest = (string) ($index['manifests'][0]['digest'] ?? '');

            return ['layout' => $layout, 'digest' => $digest, 'platform' => $platform, 'reused' => true];
        }
        $temporary = $this->store . '/.build-' . bin2hex(random_bytes(8));
        mkdir($temporary, 0700, true);

        try {
            $this->copyTree($source, $temporary);
            $this->remove($temporary . '/.git');
            $this->remove($temporary . '/tests');
            $this->remove($temporary . '/tools');
            $this->remove($temporary . '/vendor');
            $this->installComposer($temporary);
            $this->materializeHelpers($temporary, $manifest, $lock, $platform);
            $buildLayout = $layout . '.build';
            $result = $this->writeOci($temporary, $buildLayout, $platform);
            rename($buildLayout, $layout);

            return ['layout' => $layout, 'digest' => $result, 'platform' => $platform, 'reused' => false];
        } finally {
            $this->remove($temporary);
        }
    }

    private function installComposer(string $root): void
    {
        if (! is_file($root . '/composer.lock')) {
            throw new PackageValidationError('plugin composer.lock is required for materialization');
        }
        $composer = getenv('COMPOSER_BINARY') ?: 'composer';
        $command = [$composer, 'install', '--working-dir=' . $root, '--no-dev', '--no-scripts', '--no-plugins', '--no-interaction', '--prefer-dist', '--optimize-autoloader'];
        $environment = getenv();
        $environment['COMPOSER_VENDOR_DIR'] = $root . '/vendor';
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);

        if (! is_resource($process)) {
            throw new PackageValidationError('Composer could not start');
        }
        $error = stream_get_contents($pipes[2]);
        $output = stream_get_contents($pipes[1]);
        $exit = proc_close($process);

        if ($exit !== 0) {
            throw new PackageValidationError('locked Composer install failed: ' . trim((string) $error . ' ' . (string) $output));
        }
    }

    /** @param array<string, mixed> $manifest @param array<string, mixed> $lock */
    private function materializeHelpers(string $root, array $manifest, array $lock, string $platform): void
    {
        $declared = is_array($manifest['helpers'] ?? null) ? $manifest['helpers'] : [];
        $locked = is_array($lock['helpers'] ?? null) ? $lock['helpers'] : [];

        foreach ($locked as $name => $input) {
            if (! is_string($name) || ! is_array($input) || ! is_array($declared[$name] ?? null)) {
                throw new PackageValidationError('helper lock does not match the plugin manifest');
            }
            $artifact = $input['platforms'][$platform] ?? null;

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

            if (! is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0755, true);
            }

            if (is_string($artifact['archive_binary'] ?? null)) {
                $extract = $root . '/.helper-extract-' . bin2hex(random_bytes(4));
                mkdir($extract, 0700, true);
                $command = ['tar', '-xJf', $download, '-C', $extract, $artifact['archive_binary']];
                $pipe = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $error = is_resource($pipe) ? stream_get_contents($pipes[2]) : '';
                $exit = is_resource($pipe) ? proc_close($pipe) : 1;
                $extracted = $extract . '/' . $artifact['archive_binary'];

                if ($exit !== 0 || ! is_file($extracted)) {
                    throw new PackageValidationError('helper archive extraction failed: ' . trim((string) $error));
                }
                copy($extracted, $destination);
                $this->remove($extract);
            } else {
                copy($download, $destination);
            }
            chmod($destination, 0555);
            unlink($download);
        }
    }

    /** @param array<string, mixed> $manifest */
    private function writeOci(string $root, string $layout, string $platform): string
    {
        mkdir($layout . '/blobs/sha256', 0700, true);
        $archive = $layout . '/layer.tar';
        $command = ['sh', '-c', 'find "$1" -exec touch -h -d @0 {} + && cd "$1" && tar --format=ustar --sort=name --mtime="UTC 1970-01-01" --owner=0 --group=0 --numeric-owner -cf "$2" .', 'builder', $root, $archive];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($process) || proc_close($process) !== 0) {
            throw new PackageValidationError('plugin layer creation failed');
        }
        $layer = hash_file('sha256', $archive) ?: '';
        rename($archive, $layout . '/blobs/sha256/' . $layer);
        $configJson = json_encode(['architecture' => substr($platform, 6), 'os' => 'linux', 'rootfs' => ['type' => 'layers', 'diff_ids' => ['sha256:' . $layer]], 'config' => ['Labels' => ['io.stashd.plugin.platform' => $platform]]], JSON_THROW_ON_ERROR);
        $config = hash('sha256', $configJson);
        file_put_contents($layout . '/blobs/sha256/' . $config, $configJson);
        $manifestJson = json_encode(['schemaVersion' => 2, 'config' => ['mediaType' => 'application/vnd.oci.image.config.v1+json', 'digest' => 'sha256:' . $config, 'size' => strlen($configJson)], 'layers' => [['mediaType' => 'application/vnd.oci.image.layer.v1.tar', 'digest' => 'sha256:' . $layer, 'size' => filesize($layout . '/blobs/sha256/' . $layer)]]], JSON_THROW_ON_ERROR);
        $digest = hash('sha256', $manifestJson);
        file_put_contents($layout . '/blobs/sha256/' . $digest, $manifestJson);
        file_put_contents($layout . '/oci-layout', '{"imageLayoutVersion":"1.0.0"}');
        file_put_contents($layout . '/index.json', json_encode(['schemaVersion' => 2, 'manifests' => [['mediaType' => 'application/vnd.oci.image.manifest.v1+json', 'digest' => 'sha256:' . $digest, 'size' => strlen($manifestJson), 'platform' => ['os' => 'linux', 'architecture' => substr($platform, 6)]]]], JSON_THROW_ON_ERROR));

        return 'sha256:' . $digest;
    }

    /** @return array<string, mixed> */
    private function jsonFile(string $path): array
    {
        $value = json_decode((string) @file_get_contents($path), true);

        return is_array($value) ? $value : throw new PackageValidationError('invalid plugin declaration: ' . $path);
    }

    private function copyTree(string $source, string $destination): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $target = $destination . '/' . substr($item->getPathname(), strlen($source) + 1);

            if ($item->isDir()) {
                if (! is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                if (! is_dir(dirname($target))) {
                    mkdir(dirname($target), 0755, true);
                }
                copy($item->getPathname(), $target);
            }
        }
    }

    private function remove(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }

        if (is_dir($path) && ! is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
        }

        if (is_dir($path) && ! is_link($path)) {
            rmdir($path);

            return;
        }

        unlink($path);
    }

    private static function platform(): string
    {
        return match (php_uname('m')) {
            'x86_64', 'amd64' => 'linux-amd64', 'aarch64', 'arm64' => 'linux-arm64', default => throw new PackageValidationError('unsupported host architecture'),
        };
    }
}
