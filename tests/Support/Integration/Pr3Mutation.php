<?php

declare(strict_types=1);

namespace Tests\Support\Integration;

use FilesystemIterator;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\Support\RecordsAndArchives\ArchiveFixture;
use Tests\Support\RecordsAndArchives\OcflInspector;

/**
 * Independently witness source mutations and real OCFL version history.
 *
 * Tests use the Python manifest only for expected bytes. All production
 * preservation and publication must happen through normal Tempest commands.
 */
final class Pr3Mutation
{
    /**
     * Change source A in place after its original Stash selection is frozen.
     *
     * Returned manifests remain outside A, allowing verification after
     * deletion. The mutator is a separate Python fixture, not an app adapter.
     *
     * @return array{source: string, preserved: string, operations: array<string, mixed>, expected: array<string, array<string, mixed>>}
     */
    public static function mutate(ArchiveFixture $archive, string $originalManifest, int $add, int $change, int $rename, int $remove): array
    {
        $source = $archive->path('inputs');
        $afterSource = $archive->path('after-source.json');
        $afterPreserved = $archive->path('after-preservation.json');
        $operations = $archive->path('mutation-operations.json');
        $result = self::python([
            'mutate',
            '--directory',
            $source,
            '--original-manifest',
            $originalManifest,
            '--after-source-manifest',
            $afterSource,
            '--after-preserved-manifest',
            $afterPreserved,
            '--operations-manifest',
            $operations,
            '--add',
            (string) $add,
            '--change',
            (string) $change,
            '--rename',
            (string) $rename,
            '--remove',
            (string) $remove,
        ]);
        Assert::assertSame(0, $result['code'], $result['output'] . $result['errors']);
        $operationsData = json_decode((string) file_get_contents($operations), true, flags: JSON_THROW_ON_ERROR);
        $manifest = json_decode((string) file_get_contents($afterPreserved), true, flags: JSON_THROW_ON_ERROR);
        $expected = [];
        foreach ($manifest['entries'] as $entry) {
            Assert::assertArrayNotHasKey($entry['path'], $expected);
            $expected[$entry['path']] = $entry;
        }

        return ['source' => $afterSource, 'preserved' => $afterPreserved, 'operations' => $operationsData, 'expected' => $expected];
    }

    /**
     * Run only the independent Python oracle; return nonzero for any missing,
     * altered or extra output, regardless of Operations Job status.
     */
    public static function verify(string $manifest, string $directory): int
    {
        return self::python(['verify', '--directory', $directory, '--manifest', $manifest])['code'];
    }

    /**
     * Execute one command through an independent Python process in the repo root.
     *
     * @param list<string> $arguments
     * @return array{code: int, output: string, errors: string}
     */
    private static function python(array $arguments): array
    {
        $pipes = [];
        $process = proc_open(
            ['python3', 'tools/fixtures/microtext_mutations.py', ...$arguments],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Python 3 is required for mutation verification.');
        }

        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'output' => $output, 'errors' => $errors];
    }

    /**
     * Build the independent inventory index once, avoiding repeated full-tree
     * scans for every historical Item in the optional mutation stress run.
     *
     * @return array<string, array{root: string, inventory: array<string, mixed>}>
     */
    private static function inventories(string $root): array
    {
        OcflInspector::assertRoot($root);
        $records = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || $file->getFilename() !== 'inventory.json') {
                continue;
            }

            $objectRoot = dirname($file->getPathname());
            if (! is_file($objectRoot . '/0=ocfl_object_1.1')) {
                continue;
            }

            OcflInspector::assertInventorySidecar($file->getPathname());
            $inventory = json_decode((string) file_get_contents($file->getPathname()), true, flags: JSON_THROW_ON_ERROR);
            Assert::assertArrayNotHasKey($inventory['id'], $records, 'Canonical object identity appears twice in the Vault.');
            $records[$inventory['id']] = ['root' => $objectRoot, 'inventory' => $inventory];
        }

        return $records;
    }

    /**
     * Verify exact OCFL state, native content hash and Item-to-Asset ownership.
     *
     * This does not use Stashd archive readers, Jobs, saved API checksums, or
     * source A. The result maps the original relative path to every physical
     * Item/Asset version and the independently computed SHA-256 of its bytes.
     *
     * @param list<array{id: string, uri: string, object_id: string}> $members
     * @return array<string, array{item: string, uri: string, asset: string, versions: list<string>, asset_versions: list<string>, hashes: array<string, string>}>
     */
    public static function history(ArchiveFixture $archive, array $members): array
    {
        $indexes = self::inventories($archive->path('vault'));
        Assert::assertCount(count($members) * 2, $indexes, 'Every Item must own exactly one canonical Asset object.');
        $history = [];
        foreach ($members as $member) {
            Assert::assertArrayHasKey($member['object_id'], $indexes);
            $itemInventory = $indexes[$member['object_id']]['inventory'];
            $versions = array_keys($itemInventory['versions']);
            $assetObject = null;
            $relativePath = null;
            $hashes = [];
            foreach ($versions as $version) {
                $record = json_decode(self::payload($indexes, $member['object_id'], $version, 'item.json'), true, flags: JSON_THROW_ON_ERROR);
                Assert::assertSame($member['object_id'], $record['object_id']);
                Assert::assertSame($member['uri'], $record['uri']);
                Assert::assertCount(1, $record['pins']);
                $path = $record['metadata']['stashd.filesystem.source']['path'] ?? null;
                Assert::assertIsString($path);
                Assert::assertSame($relativePath ?? $path, $path, 'A single Item must not silently change its source-path identity.');
                $relativePath = $path;
                $pin = $record['pins'][0];
                Assert::assertSame($version, $pin['version'], 'Each new Item revision must pin its corresponding Asset revision.');
                $assetObject ??= $pin['object_id'];
                Assert::assertSame($assetObject, $pin['object_id'], 'A content revision cannot mint a second Asset identity.');
                Assert::assertArrayHasKey($assetObject, $indexes);
                $asset = json_decode(self::payload($indexes, $assetObject, $pin['version'], '.stashd/asset.json'), true, flags: JSON_THROW_ON_ERROR);
                Assert::assertSame($member['uri'], $asset['item_uri']);
                Assert::assertSame($member['object_id'], $asset['item_object_id']);
                $hashes[$version] = hash('sha256', self::payload($indexes, $assetObject, $pin['version'], 'data.bin'));
            }

            Assert::assertNotNull($relativePath);
            Assert::assertArrayNotHasKey($relativePath, $history, 'Different Items cannot claim the same original path.');
            $assetVersions = array_keys($indexes[$assetObject]['inventory']['versions']);
            Assert::assertSame($versions, $assetVersions, 'Each Item revision must pin a retained matching Asset revision.');
            $history[$relativePath] = [
                'item' => $member['object_id'],
                'uri' => $member['uri'],
                'asset' => $assetObject,
                'versions' => $versions,
                'asset_versions' => $assetVersions,
                'hashes' => $hashes,
            ];
        }

        return $history;
    }

    /**
     * Independently resolve one OCFL logical file using its exact version's
     * state and physical manifest rather than the object head.
     *
     * @param array<string, array{root: string, inventory: array<string, mixed>}> $objects
     */
    private static function payload(array $objects, string $objectId, string $version, string $logical): string
    {
        Assert::assertArrayHasKey($objectId, $objects);
        $object = $objects[$objectId];
        $inventory = $object['inventory'];
        Assert::assertArrayHasKey($version, $inventory['versions']);
        foreach ($inventory['versions'][$version]['state'] as $hash => $names) {
            if (! in_array($logical, $names, true)) {
                continue;
            }

            $paths = $inventory['manifest'][$hash] ?? [];
            Assert::assertNotEmpty($paths);
            $path = $paths[0];
            Assert::assertIsString($path);
            Assert::assertFalse(str_starts_with($path, '/') || str_contains($path, '..'));
            $physical = $object['root'] . '/' . $path;
            Assert::assertFileExists($physical);
            Assert::assertSame($hash, hash_file($inventory['digestAlgorithm'], $physical));

            return (string) file_get_contents($physical);
        }

        Assert::fail('OCFL does not contain ' . $objectId . '/' . $version . '/' . $logical);
    }

    /**
     * Require frozen Broadcast rows to use the exact versions expected for
     * each canonical Item; the caller supplies values read from physical OCFL.
     *
     * @param array<string, string> $versions Item object ID to exact OCFL revision.
     */
    public static function assertFrozen(\PDO $database, string $run, array $versions): void
    {
        $query = $database->prepare('SELECT item_id, version FROM configuration_proof.broadcast_selection WHERE run_id = ?');
        $query->execute([$run]);
        $saved = [];
        foreach ($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            Assert::assertArrayNotHasKey($row['item_id'], $saved);
            $saved[$row['item_id']] = $row['version'];
        }

        ksort($saved);
        ksort($versions);
        Assert::assertSame($versions, $saved, 'Frozen selection must retain every chosen exact Item revision.');
    }

    /**
     * Perform a real Vault-only Broadcast for an existing frozen selection,
     * verifying the complete committed publication-report count afterward.
     */
    public static function publish(ArchiveFixture $archive, array $descriptor, string $run, int $expectedCount): void
    {
        $job = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:build', $run]);
        Assert::assertNotEmpty($job['assignment_id']);
        Pr2Directory::settle($archive, $descriptor, $job['assignment_id'], 600);
        $cursor = -1;
        $seen = [];
        do {
            $arguments = ['broadcast:publication', $run];
            if ($cursor >= 0) {
                $arguments[] = (string) $cursor;
            }

            $page = Pr1RoundTrip::command($archive, $descriptor, $arguments);
            Assert::assertSame('complete', $page['run']['status']);
            Assert::assertLessThanOrEqual(100, count($page['results']));
            foreach ($page['results'] as $report) {
                Assert::assertSame('file', $report['kind']);
                Assert::assertArrayNotHasKey($report['position'], $seen, 'Broadcast result positions must not repeat.');
                $seen[$report['position']] = true;
                $cursor = (int) $report['position'];
            }
        } while ($page['results'] !== []);

        Assert::assertCount($expectedCount, $seen);
    }
}
