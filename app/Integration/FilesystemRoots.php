<?php

namespace App\Integration;

use InvalidArgumentException;

/**
 * Check local directory paths without creating directories or following links.
 *
 * The operator must control these paths. Check again when using them because
 * a successful configuration check cannot prevent later changes on disk.
 */
final readonly class FilesystemRoots
{
    /**
     * Resolve an absolute directory path, allowing missing trailing directories when requested.
     *
     * Every existing part must be an accessible directory. Reject links and dot
     * components before resolving the path, so resolution cannot hide an unsafe part.
     */
    public function canonical(string $path, bool $allowMissing = false): string
    {
        if (! str_starts_with($path, '/')) {
            throw new InvalidArgumentException('Filesystem roots must be absolute local directory paths.');
        }
        $path = rtrim($path, '/');
        if ($path === '') {
            return '/';
        }
        $this->relative(substr($path, 1));
        $parts = explode('/', substr($path, 1));
        $resolved = '';
        foreach ($parts as $part) {
            $resolved .= '/' . $part;
            clearstatcache(true, $resolved);
            if (is_link($resolved)) {
                throw new InvalidArgumentException('Filesystem roots may not traverse symbolic links.');
            }
            if (file_exists($resolved)) {
                if (! is_dir($resolved) || ! is_readable($resolved) || ! is_executable($resolved)) {
                    throw new InvalidArgumentException('Filesystem root components must be accessible directories.');
                }
                $real = realpath($resolved);
                if ($real === false) {
                    throw new InvalidArgumentException('Could not resolve Filesystem root.');
                }
                $resolved = $real;
            } elseif (! $allowMissing) {
                throw new InvalidArgumentException('Filesystem input must be an existing readable directory.');
            }
        }
        if (! $allowMissing && ! is_readable($resolved)) {
            throw new InvalidArgumentException('Filesystem input must be an existing readable directory.');
        }

        return $resolved;
    }

    /**
     * Check a relative path without changing its name or accessing the filesystem.
     *
     * Reject absolute paths, dot components, ambiguous separators and unsupported
     * bytes. Allow an empty path only when requested for the scan's root frame.
     */
    public function relative(string $path, bool $allowEmpty = false): void
    {
        if ($path === '' && $allowEmpty) {
            return;
        }
        if (str_starts_with($path, '/') || str_contains($path, '\\')) {
            throw new InvalidArgumentException('Filesystem names must be relative paths with slash separators.');
        }
        if (preg_match('//u', $path) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            throw new InvalidArgumentException('Filesystem names require supported UTF-8 bytes.');
        }
        if (array_intersect(explode('/', $path), ['', '.', '..']) !== []) {
            throw new InvalidArgumentException('Filesystem paths may not contain traversal or empty components.');
        }
    }

    /**
     * Reject directories that are equal or nested within each other.
     *
     * Compare whole directory names so a shared text prefix alone is allowed.
     */
    public function requireSeparate(string $left, string $right): void
    {
        if ($left === $right || str_starts_with($left, rtrim($right, '/') . '/') || str_starts_with($right, rtrim($left, '/') . '/')) {
            throw new InvalidArgumentException('Input, Vault and Broadcast roots must not overlap.');
        }
    }
}
