<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\Config\StashdConfig;
use App\System\Storage\PathSanitizer;
use InvalidArgumentException;
use Tempest\Support\Filesystem;
use Tempest\Support\Filesystem\Exceptions\RuntimeException as FilesystemException;

final readonly class BroadcastPathBuilder
{
    private const string OWNERSHIP_MARKER = '.stashd-broadcast';

    public function __construct(
        private StashdConfig $config,
    ) {}

    public function broadcastRoot(BroadcastRecord $broadcast): string
    {
        $override = $this->validateDestinationOverride(
            is_string($broadcast->settings['destination_path'] ?? null)
                ? $broadcast->settings['destination_path']
                : null,
        );

        $parent = $override
            ?? rtrim($this->config->broadcastsPath(), '/') . '/' . PathSanitizer::sanitizeSegment($broadcast->type);

        $leaf = PathSanitizer::sanitizeBroadcastSegment($broadcast->name);

        return rtrim($parent, '/') . '/' . $leaf;
    }

    public function broadcastFile(BroadcastRecord $broadcast, string ...$segments): string
    {
        $parts = [$this->broadcastRoot($broadcast)];

        foreach ($segments as $segment) {
            $parts[] = PathSanitizer::sanitizeBroadcastSegment($segment);
        }

        return implode('/', $parts);
    }

    public function relativeFile(string ...$segments): string
    {
        $parts = [];

        foreach ($segments as $segment) {
            $parts[] = PathSanitizer::sanitizeBroadcastSegment($segment);
        }

        return implode('/', $parts);
    }

    /**
     * Validates a user-supplied destination_path override. Returns the trimmed,
     * validated path (unchanged, not canonicalized -- broadcastRoot() appends a
     * broadcast-owned leaf below it regardless) or null when no override was given.
     */
    public function validateDestinationOverride(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $trimmed = trim($path);

        if ($trimmed === '') {
            return null;
        }

        if (! str_starts_with($trimmed, '/') || str_contains($trimmed, '..')) {
            throw new InvalidArgumentException('destination_path must be an absolute path without ".." segments.');
        }

        $resolved = $this->canonicalize($trimmed);

        foreach ($this->protectedRoots() as $protected) {
            $resolvedProtected = $this->canonicalize($protected);

            if (str_starts_with($resolved . '/', $resolvedProtected . '/')
                || str_starts_with($resolvedProtected . '/', $resolved . '/')
            ) {
                throw new InvalidArgumentException('destination_path cannot overlap a Stashd-managed storage root.');
            }
        }

        return $trimmed;
    }

    /**
     * Claims the broadcast's root directory: creates and marks it on first use,
     * or verifies an existing directory is already owned by this broadcast.
     * Write-time only -- call exclusively from publish().
     */
    public function claimRoot(BroadcastRecord $broadcast): string
    {
        $root = $this->broadcastRoot($broadcast);

        if (! Filesystem\is_directory($root)) {
            try {
                Filesystem\create_directory($root, 0o775);
            } catch (FilesystemException) {
                throw BroadcastException::withCode(
                    'broadcast_publish_failed',
                    'Could not create broadcast directory.',
                );
            }

            Filesystem\write_file(rtrim($root, '/') . '/' . self::OWNERSHIP_MARKER, (string) $broadcast->id);

            return $root;
        }

        $this->assertMarkerMatches($root, $broadcast);

        return $root;
    }

    /**
     * Read-only ownership check -- never creates a directory. Call from prune()
     * before deleting anything, and from verify() before trusting file state.
     */
    public function assertOwnsRoot(BroadcastRecord $broadcast): string
    {
        $root = $this->broadcastRoot($broadcast);

        if (! Filesystem\is_directory($root)) {
            return $root;
        }

        $this->assertMarkerMatches($root, $broadcast);

        return $root;
    }

    private function assertMarkerMatches(string $root, BroadcastRecord $broadcast): void
    {
        $markerPath = rtrim($root, '/') . '/' . self::OWNERSHIP_MARKER;
        $marker = Filesystem\is_file($markerPath) ? trim(Filesystem\read_file($markerPath)) : null;

        if ($marker !== (string) $broadcast->id) {
            throw BroadcastException::withCode(
                'broadcast_destination_conflict',
                "Target directory is not managed by this broadcast: {$root}",
            );
        }
    }

    /** @return list<string> */
    private function protectedRoots(): array
    {
        return [
            $this->config->vaultPath(),
            $this->config->dataPath,
            $this->config->tempPath(),
            $this->config->cachePath(),
            $this->config->backupsPath(),
            $this->config->broadcastsPath(),
        ];
    }

    private function canonicalize(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');
        $resolved = realpath($normalized);

        if ($resolved !== false) {
            return str_replace('\\', '/', $resolved);
        }

        // Path doesn't exist yet -- walk up to the nearest existing ancestor so
        // traversal via a not-yet-created descendant can't dodge the denylist.
        $ancestor = dirname($normalized);

        while ($ancestor !== '/' && $ancestor !== '.') {
            $resolvedAncestor = realpath($ancestor);

            if ($resolvedAncestor !== false) {
                $suffix = substr($normalized, strlen($ancestor));

                return str_replace('\\', '/', $resolvedAncestor) . $suffix;
            }

            $ancestor = dirname($ancestor);
        }

        return $normalized;
    }
}
