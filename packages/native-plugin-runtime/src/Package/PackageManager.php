<?php

declare(strict_types=1);

namespace Stashd\NativeRuntime\Package;

use RuntimeException;

final class PackageValidationError extends RuntimeException
{
}
final class PackageStateError extends RuntimeException
{
}

final readonly class PackageManifest
{
    /**
     * @param list<string> $extensions
     * @param list<string> $architectures
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
    ) {
    }

    public static function fromFile(string $path, string $apiVersion = '0.1', ?string $architecture = null): self
    {
        if (!is_file($path)) {
            throw new PackageValidationError('plugin.json is missing');
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new PackageValidationError('plugin.json is invalid JSON', 0, $exception);
        }
        if (!is_array($data)) {
            throw new PackageValidationError('plugin.json must be an object');
        }
        foreach (['id', 'name', 'version', 'runtime', 'api_version', 'entrypoint'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') {
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
        if (!is_array($extensions) || !array_is_list($extensions) || !is_array($architectures) || !array_is_list($architectures)) {
            throw new PackageValidationError('manifest compatibility declarations are invalid');
        }
        $extensionNames = [];
        foreach ($extensions as $extension) {
            if (!is_string($extension) || preg_match('/^[a-zA-Z0-9_]+$/', $extension) !== 1 || !extension_loaded($extension)) {
                throw new PackageValidationError('required PHP extension is unavailable');
            }
            $extensionNames[] = $extension;
        }
        $architectureNames = [];
        foreach ($architectures as $architectureName) {
            if (!is_string($architectureName)) {
                throw new PackageValidationError('manifest architecture is invalid');
            }
            $architectureNames[] = $architectureName;
        }
        if ($architectureNames !== [] && $architecture !== null && !in_array($architecture, $architectureNames, true)) {
            throw new PackageValidationError('plugin architecture is unsupported');
        }
        $phpConstraint = is_string($requires['php'] ?? null) ? $requires['php'] : '>=8.5';
        if (!self::phpSatisfies($phpConstraint)) {
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
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('package directory could not be created');
            }
        }
    }

    public function install(string $archive, string $expectedSha256): PackageManifest
    {
        if (!is_file($archive) || !hash_equals(strtolower($expectedSha256), hash_file('sha256', $archive) ?: '')) {
            throw new PackageValidationError('package checksum mismatch');
        }
        $temporary = $this->staging . '/install-' . bin2hex(random_bytes(10));
        mkdir($temporary, 0700, true);
        try {
            TarArchive::extract($archive, $temporary);
            $manifest = PackageManifest::fromFile($temporary . '/plugin.json', $this->apiVersion, $this->architecture ?? self::architecture());
            $entrypoint = $temporary . '/' . $manifest->entrypoint;
            if (!is_file($entrypoint)) {
                throw new PackageValidationError('manifest entrypoint is missing');
            }
            $destination = $this->packages . '/' . $manifest->id . '/' . $manifest->version;
            if (file_exists($destination) || is_link($destination)) {
                throw new PackageStateError('plugin version is already installed');
            }
            $parent = dirname($destination);
            if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                throw new PackageStateError('package version directory could not be created');
            }
            if (!rename($temporary, $destination)) {
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

    public function activate(string $id, string $version): void
    {
        $this->validateId($id);
        $this->validateVersion($version);
        $package = $this->packages . '/' . $id . '/' . $version;
        if (!is_dir($package) || !is_file($package . '/plugin.json')) {
            throw new PackageStateError('plugin version is not installed');
        }
        $current = $this->active . '/' . $id;
        $temporary = $this->active . '/.' . $id . '-' . bin2hex(random_bytes(8));
        if (!symlink('../packages/' . $id . '/' . $version, $temporary)) {
            throw new PackageStateError('active version link could not be prepared');
        }
        if (!rename($temporary, $current)) {
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
        if (is_dir($path)) {
            $this->makeMutable($path);
            $this->removeTree($path);
        }
    }

    public function link(string $id, string $source): PackageManifest
    {
        $this->validateId($id);
        $source = realpath($source) ?: throw new PackageValidationError('linked source does not exist');
        $manifest = PackageManifest::fromFile($source . '/plugin.json', $this->apiVersion, $this->architecture ?? self::architecture());
        if (!is_file($source . '/' . $manifest->entrypoint)) {
            throw new PackageValidationError('linked entrypoint is missing');
        }
        $this->disable($id);
        $link = $this->links . '/' . $id;
        if (is_link($link) || file_exists($link)) {
            $this->removeTree($link);
        }
        if (!symlink($source, $link)) {
            throw new PackageStateError('development link could not be created');
        }
        $temporary = $this->active . '/.' . $id . '-link-' . bin2hex(random_bytes(8));
        if (!symlink('../links/' . $id, $temporary) || !rename($temporary, $this->active . '/' . $id)) {
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
        if (!is_link($path)) {
            return null;
        }
        $manifest = realpath($path) . '/plugin.json';
        if (!is_file($manifest)) {
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

    private function validateId(string $id): void
    {
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/', $id) !== 1) {
            throw new PackageStateError('plugin ID is invalid');
        }
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
            'x86_64', 'amd64' => 'amd64', 'aarch64', 'arm64' => 'arm64', default => php_uname('m')
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
        if (is_dir($path)) {
            chmod($path, $directoryMode);
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->walkMode($path . '/' . $entry, $fileMode, $directoryMode);
                }
            } return;
        }
        chmod($path, $fileMode);
    }
    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}

final class TarArchive
{
    public static function extract(string $archive, string $destination): void
    {
        $stream = self::open($archive);
        $seen = [];
        try {
            while (true) {
                $header = self::read($stream, 512);
                if ($header === '' || strlen($header) < 512) {
                    throw new PackageValidationError('archive is truncated');
                }
                if (trim($header, "\0") === '') {
                    break;
                }
                $path = self::path($header);
                $type = $header[156] ?? "\0";
                $size = self::octal(substr($header, 124, 12));
                if ($path === '' && $type === '5') {
                    self::skipPadding($stream, $size);
                    continue;
                }
                if ($path === '' || isset($seen[$path])) {
                    throw new PackageValidationError('archive contains a duplicate or empty path');
                }
                $seen[$path] = true;
                self::validatePath($path);
                if ($type === '1' || $type === '2') {
                    throw new PackageValidationError('archive links are not permitted');
                }
                if ($type !== "\0" && $type !== '0' && $type !== '5') {
                    throw new PackageValidationError('archive entry type is unsupported');
                }
                $target = $destination . '/' . $path;
                if ($type === '5') {
                    if (!mkdir($target, 0700, true) && !is_dir($target)) {
                        throw new PackageValidationError('archive directory could not be extracted');
                    }
                } else {
                    $parent = dirname($target);
                    if (!@mkdir($parent, 0700, true) && !is_dir($parent)) {
                        throw new PackageValidationError('archive parent could not be created');
                    }
                    $output = fopen($target, 'xb');
                    if ($output === false) {
                        throw new PackageValidationError('archive file could not be created');
                    }
                    self::copy($stream, $output, $size);
                    fclose($output);
                }
                self::skipPadding($stream, $size);
            }
        } finally {
            self::close($stream);
        }
    }

    /** @return resource */
    private static function open(string $archive)
    {
        $stream = str_ends_with($archive, '.gz') ? gzopen($archive, 'rb') : fopen($archive, 'rb');
        if ($stream === false) {
            throw new PackageValidationError('archive could not be opened');
        }
        return $stream;
    }
    /** @param resource $stream */
    private static function read($stream, int $length): string
    {
        $value = get_resource_type($stream) === 'stream' ? fread($stream, max(1, $length)) : gzread($stream, max(1, $length));
        return $value === false ? '' : $value;
    }
    /**
     * @param resource $input
     * @param resource $output
     */
    private static function copy($input, $output, int $size): void
    {
        $remaining = $size;
        while ($remaining > 0) {
            $chunk = self::read($input, min(8192, $remaining));
            if ($chunk === '') {
                throw new PackageValidationError('archive file is truncated');
            } fwrite($output, $chunk);
            $remaining -= strlen($chunk);
        }
    }
    /** @param resource $stream */
    private static function skipPadding($stream, int $size): void
    {
        $padding = (512 - ($size % 512)) % 512;
        if ($padding > 0) {
            self::read($stream, $padding);
        }
    }
    /** @param resource $stream */
    private static function close($stream): void
    {
        if (is_resource($stream)) {
            get_resource_type($stream) === 'stream' ? fclose($stream) : gzclose($stream);
        }
    }
    private static function path(string $header): string
    {
        $name = rtrim(substr($header, 0, 100), "\0 ");
        $prefix = rtrim(substr($header, 345, 155), "\0 ");
        $path = $prefix === '' ? $name : $prefix . '/' . $name;
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        return $path;
    }
    private static function validatePath(string $path): void
    {
        $parts = explode('/', $path);
        if (str_starts_with($path, '/') || in_array('', $parts, true) || in_array('..', $parts, true) || in_array('.', $parts, true)) {
            throw new PackageValidationError('archive path is unsafe');
        }
    }
    private static function octal(string $value): int
    {
        return (int) (octdec(trim($value, "\0 ")) ?: 0);
    }
}
