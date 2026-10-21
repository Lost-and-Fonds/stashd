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
        if (! is_dir($root)) {
            throw new RuntimeException('The OCFL storage root does not exist.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getFilename() !== 'inventory.json') {
                continue;
            }

            $path = $file->getPathname();
            $objectRoot = dirname($path);

            // Ignore optional version-directory inventory copies. All file
            // paths in a manifest are relative to the OCFL object root.
            if (! is_file($objectRoot . '/0=ocfl_object_1.1')) {
                continue;
            }

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
        $inventory = json_decode((string) file_get_contents($inventoryPath), true, 512, JSON_THROW_ON_ERROR);
        $algorithm = $inventory['digestAlgorithm'] ?? null;
        Assert::assertContains($algorithm, ['sha512', 'sha256']);

        $sidecar = $inventoryPath . '.' . $algorithm;
        Assert::assertFileExists($sidecar);
        $tokens = preg_split('/\s+/', trim((string) file_get_contents($sidecar)));
        Assert::assertSame(hash_file($algorithm, $inventoryPath), strtolower($tokens[0] ?? ''));
    }

    /**
     * Verify a known acknowledged snapshot using only native JSON and hashing.
     */
    public static function assertAcknowledgedHistory(string $root, string $id, string $head): void
    {
        $digest = hash('sha256', $id);
        $path = $root . '/' . substr($digest, 0, 2) . '/' . substr($digest, 2, 2) . '/' . $digest;
        $inventoryPath = $path . '/' . $head . '/inventory.json';
        self::assertInventorySidecar($inventoryPath);
        $raw = (string) file_get_contents($inventoryPath);
        $inventory = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        Assert::assertSame($id, $inventory['id']);
        Assert::assertSame($head, $inventory['head']);
        $algorithm = $inventory['digestAlgorithm'];
        Assert::assertSame(hash($algorithm, $raw) . ' inventory.json', trim((string) file_get_contents($path . '/inventory.json.' . $algorithm)));
        foreach ($inventory['versions'] as $version => $entry) {
            $historicalPath = $path . '/' . $version . '/inventory.json';
            self::assertInventorySidecar($historicalPath);
            $historical = json_decode((string) file_get_contents($historicalPath), true, flags: JSON_THROW_ON_ERROR);
            Assert::assertSame($entry, $historical['versions'][$version]);
            foreach ($entry['state'] as $hash => $names) {
                Assert::assertNotEmpty($names);
                Assert::assertArrayHasKey($hash, $inventory['manifest']);
            }
        }
        foreach ($inventory['manifest'] as $hash => $paths) {
            foreach ($paths as $relative) {
                Assert::assertSame($hash, hash_file($algorithm, $path . '/' . $relative));
            }
        }
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
            if (! is_array($paths) || ! in_array($logicalPath, $paths, true)) {
                continue;
            }

            foreach ($inventory['manifest'][$digest] ?? [] as $contentPath) {
                if (! is_string($contentPath) || str_starts_with($contentPath, '/') || str_contains($contentPath, '..')) {
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
