<?php

declare(strict_types=1);

namespace Tests\Support\Integration;

use Closure;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

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
        return PreservationWitness::members($archive, $descriptor, $stash);
    }

    /**
     * Execute bounded batches until all sources settle, without treating the run
     * inspector's status as evidence that the Vault or Broadcast is correct.
     *
     * The read-only input:admission receipt is a proposed PR 2 inspection seam:
     * run_id, total, admitted, settled, outstanding and status. The CLI can be
     * renamed in review, but accurate persisted progress and bounded pages remain required.
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
     * Read persisted progress without treating page size as a bound on outstanding Assignments.
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
        PreservationWitness::assignments($database, $run, $count, $allowRetries);
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
     * Prove every Stash Item has one exact canonical v1 Asset, the original
     * relative path and external-manifest bytes, with no extra OCFL objects.
     * The optional observer is called only after each Item's pins, versions and bytes pass;
     * full membership must still pass after the loop before the whole check succeeds.
     *
     * @param array<string, array{index: int, path: string, bytes: int, sha256: string}> $entries
     * @param list<array{id: string, uri: string, object_id: string}> $members
     * @return array<string, array{item: string, asset: string, item_versions: list<string>, asset_versions: list<string>, item_inventory_hash: string, asset_inventory_hash: string}>
     */
    public static function assertPreserved(ArchiveFixture $archive, array $members, array $entries, ?Closure $progress = null): array
    {
        return PreservationWitness::verify($archive, $members, $entries, $progress);
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
