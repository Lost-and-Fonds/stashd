<?php

namespace App\Integration;

use InvalidArgumentException;

/**
 * Validate local Filesystem roots without creating directories or following links.
 *
 * Configuration checks assume operator-controlled paths. Execution must recheck
 * confinement because these checks do not freeze directory entries against mutation.
 */
final readonly class FilesystemRoots
{
    /**
     * Resolve an absolute directory, permitting missing suffixes only when requested.
     *
     * Existing components must be real directories. Reject traversal and links
     * before realpath can hide them; missing suffixes inherit their resolved parent.
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
     * Require an exact supported relative name without filesystem access or sanitization.
     *
     * The empty path is permitted only for the traversal's root frame. All other
     * callers reject absolute names, traversal, ambiguous separators and unsupported bytes.
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
     * Reject equal roots and ancestry in either direction using directory boundaries.
     */
    public function requireSeparate(string $left, string $right): void
    {
        if ($left === $right || str_starts_with($left, rtrim($right, '/') . '/') || str_starts_with($right, rtrim($left, '/') . '/')) {
            throw new InvalidArgumentException('Input, Vault and Broadcast roots must not overlap.');
        }
    }
}
