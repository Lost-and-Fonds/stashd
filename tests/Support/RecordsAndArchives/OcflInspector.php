<?php

declare(strict_types=1);

namespace Tests\Support\RecordsAndArchives;

use FilesystemIterator;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Reads actual OCFL inventories and physical payloads without asking Stashd.
 *
 * This is a limited independent smoke check, not a standards validator.
 */
final class OcflInspector
{
    /**
     * Require an OCFL 1.1 storage-root marker.
     */
    public static function assertRoot(string $root): void
    {
        $marker = $root . '/0=ocfl_1.1';
        Assert::assertFileExists($marker);
        Assert::assertSame('ocfl_1.1', trim((string) file_get_contents($marker)));
    }

    /**
     * Locate a physical object inventory using its permanent OCFL identifier.
     *
     * @return array{path: string, inventory: array<string, mixed>}
     */
    public static function inventoryFor(string $root, string $objectId): array
    {
        if (!is_dir($root)) {
            throw new RuntimeException('The OCFL storage root does not exist.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() !== 'inventory.json') {
                continue;
            }

            $path = $file->getPathname();
            $inventory = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            if (is_array($inventory) && ($inventory['id'] ?? null) === $objectId) {
                return ['path' => $path, 'inventory' => $inventory];
            }
        }

        throw new RuntimeException('Could not find a physical inventory for the OCFL object.');
    }

    /**
     * Verify at least one standard inventory checksum sidecar using raw bytes.
     */
    public static function assertInventorySidecar(string $inventoryPath): void
    {
        $found = false;

        foreach (['sha512', 'sha256'] as $algorithm) {
            $sidecar = $inventoryPath . '.' . $algorithm;

            if (!is_file($sidecar)) {
                continue;
            }

            $found = true;
            $tokens = preg_split('/\s+/', trim((string) file_get_contents($sidecar)));
            Assert::assertSame(hash_file($algorithm, $inventoryPath), strtolower($tokens[0] ?? ''));
        }

        Assert::assertTrue($found, 'The object inventory must have a digest sidecar.');
    }

    /**
     * Resolve an exact version and logical path to immutable payload bytes.
     */
    public static function payloadPath(
        string $root,
        string $objectId,
        string $version,
        string $logicalPath,
    ): string {
        $found = self::inventoryFor($root, $objectId);
        $inventory = $found['inventory'];
        $state = $inventory['versions'][$version]['state'] ?? [];

        foreach ($state as $digest => $paths) {
            if (!is_array($paths) || !in_array($logicalPath, $paths, true)) {
                continue;
            }

            foreach ($inventory['manifest'][$digest] ?? [] as $contentPath) {
                if (!is_string($contentPath) || str_starts_with($contentPath, '/') || str_contains($contentPath, '..')) {
                    continue;
                }

                $physical = dirname($found['path']) . '/' . $contentPath;

                if (is_file($physical)) {
                    return $physical;
                }
            }
        }

        throw new RuntimeException('No physical content found for the specified logical Asset version.');
    }
}
