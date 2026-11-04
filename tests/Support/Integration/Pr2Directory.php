<?php

declare(strict_types=1);

namespace Tests\Support\Integration;

use Closure;
use FilesystemIterator;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\Support\RecordsAndArchives\ArchiveFixture;
use Tests\Support\RecordsAndArchives\OcflInspector;

use function configuration_cli;

/**
 * Exercise directory-scale Input, Catalogue and Broadcast through actual CLI processes.
 *
 * Expected files come only from the external Microtext manifest. Production services
 * perform all discovery, preservation and publication; this witness reads saved
 * PostgreSQL rows and physical OCFL bytes without fabricating application success.
 */
final class Pr2Directory
{
    /**
     * Generate a disposable corpus with a manifest outside its Input root.
     *
     * The manifest is not the pinned 10,000-file fixture; the generator itself is
     * deterministic for every chosen count and the external verifier owns the truth.
     *
     * @return array{source: string, manifest: string, entries: array<string, array{index: int, path: string, bytes: int, sha256: string}>}
     */
    public static function corpus(ArchiveFixture $archive, int $count): array
    {
        $source = $archive->path('inputs');
        $manifest = $archive->path('directory-manifest.json');
        $pipes = [];
        $process = proc_open(
            ['python3', 'tools/fixtures/microtext.py', 'generate', '--directory', $source, '--manifest', $manifest, '--count', (string) $count],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Python 3 is required for Microtext.');
        }

        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        Assert::assertSame(0, proc_close($process), (string) $out . (string) $err);
        $data = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        Assert::assertSame($count, $data['count']);
        $entries = [];
        foreach ($data['entries'] as $entry) {
            Assert::assertArrayNotHasKey($entry['path'], $entries, 'Generated paths must be unique.');
            $entries[$entry['path']] = $entry;
        }

        return ['source' => $source, 'manifest' => $manifest, 'entries' => $entries];
    }

    /**
     * Admit discovery through Operations and require every found file to become a
     * distinct Stash member; this will fail against PR 1's single-file restriction.
     *
     * @return array{stash: string, input: string, run: string}
     */
    public static function discover(ArchiveFixture $archive, array $descriptor, string $source, int $count, ?Closure $onSettled = null): array
    {
        Pr1RoundTrip::command($archive, $descriptor, ['vault:init']);
        Pr1RoundTrip::command($archive, $descriptor, ['integration:init']);
        $stash = Pr1RoundTrip::command($archive, $descriptor, ['stash:create', 'Microtext directory']);
        $input = Pr1RoundTrip::command($archive, $descriptor, ['input:create', 'filesystem', $source]);
        Pr1RoundTrip::command($archive, $descriptor, ['stash:input:attach', $stash['id'], $input['id']]);
        $discovery = Pr1RoundTrip::command($archive, $descriptor, ['input:discover', $input['id']]);
        Assert::assertNotEmpty($discovery['assignment_id'] ?? null);
        Assert::assertNotEmpty($discovery['run_id'] ?? null);
        self::settle($archive, $descriptor, $discovery['assignment_id'], $count > 100 ? 600 : 180);
        $onSettled?->__invoke();
        $inspection = Pr1RoundTrip::command($archive, $descriptor, ['input:discovery', $discovery['run_id']]);
        Assert::assertSame('finished', $inspection['status']);
        Assert::assertSame('exhaustive', $inspection['coverage']['kind']);
        Assert::assertSame($count, $inspection['discovered_count']);
        $cursor = '';
        $found = [];
        do {
            $page = Pr1RoundTrip::command($archive, $descriptor, ['input:discoveries', $discovery['run_id'], $cursor]);
            Assert::assertLessThanOrEqual(100, count($page['items']));
            foreach ($page['items'] as $item) {
                Assert::assertArrayNotHasKey($item['id'], $found, 'Discovery repeated a source across pages.');
                $found[$item['id']] = true;
            }

            $cursor = $page['next'];
        } while ($cursor !== null);

        Assert::assertCount($count, $found);
        Assert::assertCount($count, self::members($archive, $descriptor, $stash['id']));

        return ['stash' => $stash['id'], 'input' => $input['id'], 'run' => $discovery['run_id']];
    }

    /**
     * Collect consecutive bounded Catalogue membership pages and reject repeated
     * cursors or duplicate Item identities instead of trusting a reported count.
     *
     * @return list<array{id: string, uri: string, object_id: string}>
     */
    public static function members(ArchiveFixture $archive, array $descriptor, string $stash): array
    {
        $cursor = '';
        $seenCursors = [];
        $identities = [];
        $members = [];
        do {
            $args = ['stash:items', $stash];
            if ($cursor !== '') {
                $args[] = $cursor;
            }

            $page = Pr1RoundTrip::command($archive, $descriptor, $args);
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
     * Execute bounded batches until all sources settle, without treating the run
     * inspector's status as evidence that the Vault or Broadcast is correct.
     *
     * The read-only input:admission receipt is a proposed PR 2 inspection seam:
     * run_id, total, admitted, settled, outstanding and status. The CLI can be
     * renamed in review, but accurate persisted progress and an upper bound cannot.
     *
     * @return array<string, mixed>
     */
    public static function acquire(ArchiveFixture $archive, array $descriptor, string $run, int $count, int $seconds = 180): array
    {
        $deadline = microtime(true) + $seconds;
        $state = [];
        do {
            $admission = Pr1RoundTrip::command($archive, $descriptor, ['input:acquire', $run]);
            Assert::assertNotEmpty($admission['assignment_id'] ?? null, 'Admission must return a durable per-file Assignment receipt.');
            $state = self::admission($archive, $descriptor, $run, $count);
            if ($state['status'] === 'complete') {
                break;
            }

            $worker = configuration_cli($archive, $descriptor, ['operations:work', '--once']);
            Assert::assertSame(0, $worker['code'], $worker['output'] . $worker['errors']);
            $state = self::admission($archive, $descriptor, $run, $count);
            if ($state['status'] === 'failed') {
                Assert::fail('Directory admission failed: ' . json_encode($state, JSON_THROW_ON_ERROR));
            }

            if ($state['status'] === 'complete') {
                break;
            }
        } while (microtime(true) < $deadline);

        Assert::assertSame('complete', $state['status'] ?? null, 'Directory acquisition did not settle before its bounded deadline.');
        Assert::assertSame($count, $state['admitted']);
        Assert::assertSame($count, $state['settled']);
        Assert::assertSame(0, $state['outstanding']);
        $cursor = '';
        $sources = [];
        do {
            $page = Pr1RoundTrip::command($archive, $descriptor, ['input:admission', $run, $cursor]);
            Assert::assertLessThanOrEqual(100, count($page['items']));
            foreach ($page['items'] as $item) {
                Assert::assertArrayNotHasKey($item['source_id'], $sources, 'Admission repeated a source across pages.');
                Assert::assertNotEmpty($item['assignment_id']);
                $sources[$item['source_id']] = true;
            }

            $cursor = $page['next'];
        } while ($cursor !== null);

        Assert::assertCount($count, $sources);

        return $state;
    }

    /**
     * Read persisted progress and enforce the hard concurrency bound at every
     * observed refill point; a separate PostgreSQL guard checks actual admission.
     *
     * @return array<string, mixed>
     */
    public static function admission(ArchiveFixture $archive, array $descriptor, string $run, int $count): array
    {
        $state = Pr1RoundTrip::command($archive, $descriptor, ['input:admission', $run]);
        Assert::assertSame($run, $state['run_id'] ?? null);
        Assert::assertArrayHasKey('cursor', $state, 'The durable last-admitted source cursor must be inspectable.');
        if ($state['cursor'] !== null) {
            Assert::assertIsString($state['cursor']);
        }

        Assert::assertSame($count, $state['total'] ?? null);
        foreach (['admitted', 'settled', 'outstanding', 'failed'] as $field) {
            Assert::assertIsInt($state[$field] ?? null, 'Progress fields must be integer counts: ' . $field);
            Assert::assertGreaterThanOrEqual(0, $state[$field]);
        }

        Assert::assertLessThanOrEqual($count, $state['admitted']);
        Assert::assertLessThanOrEqual($state['admitted'], $state['settled'] + $state['outstanding']);
        Assert::assertLessThanOrEqual(100, $state['outstanding']);
        Assert::assertLessThanOrEqual($state['outstanding'], $state['failed']);

        return $state;
    }

    /**
     * Require exactly one durable per-file acquisition input for every discovered
     * source ID. A run-wide Job containing all files cannot satisfy this evidence.
     * Recovery may legitimately admit another Assignment after terminal failure;
     * the normal path still expects one initial Demand per file.
     *
     * Each per-file Job has persisted runId and sourceId in its serialized input.
     * The naming is a proposed internal boundary for PR 2, not a public SDK API.
     */
    public static function assertPerFileAssignments(\PDO $database, string $run, int $count, bool $allowRetries = false): void
    {
        $statement = $database->prepare(<<<'SQL'
        SELECT d.payload::jsonb ->> 'sourceId' AS source_id, a.uuid::text AS assignment_id
        FROM configuration_proof.operation_demands d
        JOIN configuration_proof.operation_assignments a ON a.uuid = d.assignment_id
        WHERE d.payload::jsonb ->> 'runId' = ?
            AND d.payload::jsonb ->> 'sourceId' IS NOT NULL
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
     * A durable cursor may pass a source only when that source already has an
     * independently visible committed per-file Demand in Operations.
     *
     * This compares the cursor with actual discovery rows and Job payloads,
     * not with a coordinator's reported admitted count.
     */
    public static function assertCursorCovered(\PDO $database, string $run, ?string $cursor): void
    {
        if ($cursor === null || $cursor === '') {
            return;
        }

        $before = $database->prepare(
            'SELECT source_id FROM configuration_proof.discovery_items WHERE run_id = ? AND source_id <= ? ORDER BY source_id',
        );
        $before->execute([$run, $cursor]);
        $expected = $before->fetchAll(\PDO::FETCH_COLUMN);
        $admitted = $database->prepare(<<<'SQL'
        SELECT DISTINCT d.payload::jsonb ->> 'sourceId' AS source_id
        FROM configuration_proof.operation_demands d
        WHERE d.payload::jsonb ->> 'runId' = ?
          AND d.payload::jsonb ->> 'sourceId' IS NOT NULL
          AND d.payload::jsonb ->> 'sourceId' <= ?
        ORDER BY source_id
        SQL);
        $admitted->execute([$run, $cursor]);
        Assert::assertSame(
            $expected,
            $admitted->fetchAll(\PDO::FETCH_COLUMN),
            'The persisted cursor skipped a source without a committed per-file Job admission.',
        );
    }

    /**
     * Index physical OCFL paths once without retaining every decoded inventory.
     * This avoids both repeated full-tree walks and scale-dependent verifier memory.
     *
     * @return array<string, string>
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
     * Read the exact logical version from an OCFL manifest and verify its native
     * digest, rather than using any application reader or trusting Job results.
     *
     * @param array{root: string, inventory: array<string, mixed>} $object
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
     * Prove every Stash Item has one exact canonical v1 Asset, the original
     * relative path and external-manifest bytes, with no extra OCFL objects.
     *
     * @param array<string, array{index: int, path: string, bytes: int, sha256: string}> $entries
     * @param list<array{id: string, uri: string, object_id: string}> $members
     * @return array<string, array{item: string, asset: string, item_versions: list<string>, asset_versions: list<string>, item_inventory_hash: string, asset_inventory_hash: string}>
     */
    public static function assertPreserved(ArchiveFixture $archive, array $members, array $entries): array
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
        }

        $expectedPaths = array_keys($entries);
        $actualPaths = array_keys($seen);
        sort($expectedPaths);
        sort($actualPaths);
        Assert::assertSame($expectedPaths, $actualPaths, 'Membership did not cover the exact reference file set.');

        return $references;
    }

    /**
     * Publish a frozen current selection through a real Operations Job, checking
     * all report pages; consumers must never recover output by reading Input A.
     *
     * @return array{broadcast: string, run: string}
     */
    public static function broadcast(ArchiveFixture $archive, array $descriptor, string $stash, int $count, ?string $broadcast = null): array
    {
        if ($broadcast === null) {
            $configured = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:create', 'filesystem', $archive->path('output')]);
            $broadcast = $configured['id'];
        }

        $selected = Pr1RoundTrip::command($archive, $descriptor, ['stash:broadcast:select', $stash, $broadcast]);
        $job = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:build', $selected['id']]);
        Assert::assertNotEmpty($job['assignment_id'] ?? null);
        self::settle($archive, $descriptor, $job['assignment_id'], $count > 100 ? 600 : 120);
        $after = -1;
        $seen = [];
        do {
            $args = ['broadcast:publication', $selected['id']];
            if ($after >= 0) {
                $args[] = (string) $after;
            }

            $page = Pr1RoundTrip::command($archive, $descriptor, $args);
            Assert::assertSame('complete', $page['run']['status']);
            Assert::assertLessThanOrEqual(100, count($page['results']));
            foreach ($page['results'] as $row) {
                Assert::assertSame('file', $row['kind']);
                Assert::assertGreaterThan($after, (int) $row['position']);
                $after = (int) $row['position'];
                $seen[] = $row;
            }
        } while ($page['results'] !== []);

        Assert::assertCount($count, $seen, 'A completed Broadcast must report exactly one file per Item.');

        return ['broadcast' => $broadcast, 'run' => $selected['id']];
    }

    /**
     * Drain real Messenger/Durable workers in fresh PHP processes until one
     * Assignment is complete, without interpreting its state as proof of bytes.
     */
    public static function settle(ArchiveFixture $archive, array $descriptor, string $assignment, int $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            $work = configuration_cli($archive, $descriptor, ['operations:work', '--once']);
            Assert::assertSame(0, $work['code'], $work['output'] . $work['errors']);
            $status = Pr1RoundTrip::command($archive, $descriptor, ['operations:inspect', $assignment]);
            if ($status['state'] === 'completed') {
                return;
            }

            Assert::assertNotSame('failed', $status['state'], 'Operations could not complete: ' . json_encode($status, JSON_THROW_ON_ERROR));
            usleep(100_000);
        } while (microtime(true) < $deadline);

        Assert::fail('Assignment did not settle through the real worker.');
    }

    /**
     * Record destination inode identities using only expected manifest paths.
     *
     * @param array<string, array{index: int, path: string, bytes: int, sha256: string}> $entries
     * @return array<string, int>
     */
    public static function inodes(string $output, array $entries): array
    {
        $ids = [];
        foreach ($entries as $relative => $entry) {
            $path = $output . '/' . $relative;
            Assert::assertFileExists($path);
            clearstatcache(true, $path);
            $inode = fileinode($path);
            Assert::assertIsInt($inode);
            $ids[$relative] = $inode;
        }

        return $ids;
    }
}
