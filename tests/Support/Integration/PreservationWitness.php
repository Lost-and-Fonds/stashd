<?php

declare(strict_types=1);

namespace Tests\Support\Integration;

use Closure;
use FilesystemIterator;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\RecordsAndArchives\ArchiveFixture;
use Tests\Support\RecordsAndArchives\OcflInspector;

use function configuration_cli;
use function configuration_result;

/**
 * Independently check Catalogue membership, exact Item/Asset pins and physical OCFL bytes.
 * These witnesses are extracted from the directory contracts, not application preservation readers.
 */
final class PreservationWitness
{
    /**
     * Compare persisted per-file Job inputs with the exact discovered source set, independently of counters.
     * Recovery may add Assignments; an ordinary first run must have one initial Demand per source.
     */
    public static function assignments(\PDO $database, string $run, int $count, bool $allowRetries = false): void
    {
        $statement = $database->prepare(<<<'SQL'
        SELECT d.payload::jsonb ->> 'sourceId' AS source_id, a.uuid::text AS assignment_id
        FROM configuration_proof.operation_demands d
        JOIN configuration_proof.operation_assignments a ON a.uuid = d.assignment_id
        WHERE d.payload::jsonb ->> 'runId' = ? AND d.payload::jsonb ->> 'sourceId' IS NOT NULL
        SQL);
        $statement->execute([$run]);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        $actual = array_unique(array_column($rows, 'source_id'));
        $expected = $database->prepare('SELECT source_id FROM configuration_proof.discovery_items WHERE run_id = ? ORDER BY source_id');
        $expected->execute([$run]);
        $sources = $expected->fetchAll(\PDO::FETCH_COLUMN);
        sort($actual);
        sort($sources);
        if ($allowRetries) {
            Assert::assertGreaterThanOrEqual($count, count($rows), 'Retryable admissions still need a persisted Job for every source.');
        } else {
            Assert::assertCount($count, $rows, 'A fault-free first run needs one durable acquisition Demand per file.');
        }

        Assert::assertSame($sources, $actual, 'Each discovered source needs its own persisted Job input.');
        Assert::assertGreaterThanOrEqual($count, count(array_unique(array_column($rows, 'assignment_id'))), 'One run-wide Assignment cannot represent individual files.');
    }

    /**
     * Read bounded Catalogue pages, rejecting duplicate Items and repeated cursors.
     */
    public static function members(ArchiveFixture $archive, array $descriptor, string $stash): array
    {
        $cursor = '';
        $seenCursors = [];
        $identities = [];
        $members = [];
        do {
            $page = configuration_result(configuration_cli($archive, $descriptor, ['stash:items', $stash, $cursor]));
            Assert::assertLessThanOrEqual(100, count($page['items']), 'Catalogue returned an unbounded membership page.');
            foreach ($page['items'] as $member) {
                Assert::assertArrayNotHasKey($member['id'], $identities, 'Catalogue returned the same Item more than once.');
                $identities[$member['id']] = true;
                $members[] = $member;
            }

            $next = $page['next'] ?? null;
            if ($next !== null) {
                Assert::assertIsString($next);
                Assert::assertNotSame($cursor, $next, 'Catalogue pagination must advance.');
                Assert::assertArrayNotHasKey($next, $seenCursors, 'Catalogue pagination loop detected.');
                $seenCursors[$next] = true;
            }

            $cursor = $next;
        } while ($cursor !== null);

        return $members;
    }

    /**
     * Index physical OCFL paths and verify inventory sidecars without retaining all decoded inventories.
     * Historical inventories must match their recorded version states; duplicate canonical IDs fail.
     */
    private static function inventories(string $root): array
    {
        OcflInspector::assertRoot($root);
        $records = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getFilename() !== 'inventory.json') {
                continue;
            }

            $objectRoot = dirname($file->getPathname());
            if (! is_file($objectRoot . '/0=ocfl_object_1.1')) {
                continue;
            }

            OcflInspector::assertInventorySidecar($file->getPathname());
            $inventory = json_decode((string) file_get_contents($file->getPathname()), true, flags: JSON_THROW_ON_ERROR);
            foreach ($inventory['versions'] as $version => $state) {
                $historical = $objectRoot . '/' . $version . '/inventory.json';
                OcflInspector::assertInventorySidecar($historical);
                $saved = json_decode((string) file_get_contents($historical), true, flags: JSON_THROW_ON_ERROR);
                Assert::assertSame($state, $saved['versions'][$version]);
            }

            Assert::assertArrayNotHasKey($inventory['id'], $records, 'Duplicate canonical OCFL object ID.');
            $records[$inventory['id']] = $objectRoot;
        }

        return $records;
    }

    /**
     * Resolve an exact logical version through the OCFL manifest and verify its physical native digest.
     */
    private static function payload(array $object, string $objectId, string $version, string $logical): string
    {
        $inventory = $object['inventory'];
        Assert::assertSame($objectId, $inventory['id'], 'A reread inventory must still identify the indexed Object.');
        Assert::assertArrayHasKey($version, $inventory['versions'], 'Missing exact OCFL version.');
        foreach ($inventory['versions'][$version]['state'] as $digest => $names) {
            if (! in_array($logical, $names, true)) {
                continue;
            }

            $paths = $inventory['manifest'][$digest] ?? [];
            Assert::assertNotEmpty($paths, 'A referenced digest requires a physical manifest path.');
            $path = $paths[0];
            Assert::assertIsString($path);
            Assert::assertFalse(str_starts_with($path, '/') || str_contains($path, '..'), 'Unsafe OCFL manifest path.');
            $physical = $object['root'] . '/' . $path;
            Assert::assertFileExists($physical);
            Assert::assertSame($digest, hash_file($inventory['digestAlgorithm'], $physical), 'OCFL manifest digest disagrees with physical bytes.');

            return (string) file_get_contents($physical);
        }

        Assert::fail('Missing exact physical payload ' . $objectId . '/' . $version . '/' . $logical);
    }

    /**
     * Prove one canonical v1 Item and Asset per source, exact relative paths and external-manifest bytes.
     * Progress follows each successful physical check; exact complete membership is checked afterwards.
     *
     * @return array<string, array{item: string, asset: string, item_versions: list<string>, asset_versions: list<string>, item_inventory_hash: string, asset_inventory_hash: string}>
     */
    public static function verify(ArchiveFixture $archive, array $members, array $entries, ?Closure $progress = null): array
    {
        $inventories = self::inventories($archive->path('vault'));
        Assert::assertCount(count($entries) * 2, $inventories, 'Expected one canonical Item and one Asset object per source.');
        Assert::assertCount(count($entries), $members);
        $seen = [];
        $references = [];
        foreach ($members as $member) {
            $itemId = $member['object_id'];
            Assert::assertArrayHasKey($itemId, $inventories);
            $itemObject = [
                'root' => $inventories[$itemId],
                'inventory' => json_decode((string) file_get_contents($inventories[$itemId] . '/inventory.json'), true, flags: JSON_THROW_ON_ERROR),
            ];
            $itemHistory = $itemObject['inventory']['versions'] ?? null;
            Assert::assertIsArray($itemHistory);
            $item = json_decode(self::payload($itemObject, $itemId, 'v1', 'item.json'), true, flags: JSON_THROW_ON_ERROR);
            Assert::assertSame($member['uri'], $item['uri']);
            Assert::assertCount(1, $item['pins']);
            $relative = $item['metadata']['stashd.filesystem.source']['path'] ?? null;
            Assert::assertIsString($relative);
            Assert::assertArrayHasKey($relative, $entries, 'Preserved Item did not originate in the independent manifest.');
            Assert::assertArrayNotHasKey($relative, $seen, 'Two Items claimed one input file.');
            $seen[$relative] = true;
            $pin = $item['pins'][0];
            $assetId = $pin['object_id'];
            Assert::assertArrayHasKey($assetId, $inventories);
            $assetObject = [
                'root' => $inventories[$assetId],
                'inventory' => json_decode((string) file_get_contents($inventories[$assetId] . '/inventory.json'), true, flags: JSON_THROW_ON_ERROR),
            ];
            $assetVersions = $assetObject['inventory']['versions'];
            Assert::assertSame(['v1'], array_keys($itemHistory), 'One unchanged Item must have only its first archival version.');
            Assert::assertSame('v1', $pin['version']);
            Assert::assertSame(['v1'], array_keys($assetVersions), 'One unchanged Asset must have only its first archival version.');
            $asset = json_decode(self::payload($assetObject, $assetId, 'v1', '.stashd/asset.json'), true, flags: JSON_THROW_ON_ERROR);
            Assert::assertSame($member['uri'], $asset['item_uri']);
            Assert::assertSame($itemId, $asset['item_object_id']);
            $data = self::payload($assetObject, $assetId, 'v1', 'data.bin');
            Assert::assertSame($entries[$relative]['bytes'], strlen($data));
            Assert::assertSame($entries[$relative]['sha256'], hash('sha256', $data));
            $references[$relative] = [
                'item' => $itemId,
                'asset' => $assetId,
                'item_versions' => array_keys($itemHistory),
                'asset_versions' => array_keys($assetVersions),
                'item_inventory_hash' => hash_file('sha256', $inventories[$itemId] . '/v1/inventory.json'),
                'asset_inventory_hash' => hash_file('sha256', $inventories[$assetId] . '/v1/inventory.json'),
            ];
            $progress?->__invoke(count($references));
        }

        $expectedPaths = array_keys($entries);
        $actualPaths = array_keys($seen);
        sort($expectedPaths);
        sort($actualPaths);
        Assert::assertSame($expectedPaths, $actualPaths, 'Membership did not cover the exact reference file set.');

        return $references;
    }
}
