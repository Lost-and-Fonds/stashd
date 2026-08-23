<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Package;

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
                    if (! mkdir($target, 0700, true) && ! is_dir($target)) {
                        throw new PackageValidationError('archive directory could not be extracted');
                    }
                } else {
                    $parent = dirname($target);
                    if (! @mkdir($parent, 0700, true) && ! is_dir($parent)) {
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
     * @param  resource  $input
     * @param  resource  $output
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
