<?php

declare(strict_types=1);

namespace Tests\Support\Integration;

use PHPUnit\Framework\Assert;
use RuntimeException;
use Tests\Support\RecordsAndArchives\ArchiveFixture;
use Tests\Support\RecordsAndArchives\OcflInspector;
use Tests\Support\RecordsAndArchives\TransactionProbe;

use function configuration_cli;
use function configuration_result;

/**
 * Verify the first real Filesystem round trip through public processes and physical evidence.
 *
 * This test-only helper does not discover, preserve or publish on Stashd's behalf.
 * All application work must cross the same CLI and Operations boundaries as users.
 * The Microtext generator/verifier is deliberately independent Python code.
 */
final class Pr1RoundTrip
{
    /**
     * Generate a single deterministic Microtext file in a disposable source root.
     *
     * The manifest lives outside the source root and survives its removal.
     *
     * @return array{source: string, manifest: string, relative: string, bytes: string, digest: string}
     */
    public static function microtext(ArchiveFixture $archive): array
    {
        $source = $archive->path('inputs');
        $manifest = $archive->path('reference-manifest.json');
        self::python([
            'generate',
            '--directory',
            $source,
            '--manifest',
            $manifest,
            '--count',
            '1',
        ]);
        /** @var array{entries: list<array{path: string, sha256: string, bytes: int}>} $decoded */
        $decoded = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        Assert::assertCount(1, $decoded['entries']);
        $entry = $decoded['entries'][0];
        $bytes = file_get_contents($source . '/' . $entry['path']);
        Assert::assertIsString($bytes);
        Assert::assertSame($entry['sha256'], hash('sha256', $bytes));

        return ['source' => $source, 'manifest' => $manifest, 'relative' => $entry['path'], 'bytes' => $bytes, 'digest' => $entry['sha256']];
    }

    /**
     * Return a verifier process's exit code without using application libraries.
     *
     * The caller can assert that genuine output succeeds and deliberately damaged
     * output fails. Verification cannot read the original Input or the Vault.
     */
    public static function verify(string $manifest, string $output): int
    {
        return self::python(['verify', '--directory', $output, '--manifest', $manifest], expected: null);
    }

    /**
     * Run a real Tempest CLI command in a fresh PHP process and decode its receipt.
     *
     * Command names and receipt fields here define the proposed PR 1 public API.
     * Missing commands must fail this contract rather than being simulated.
     *
     * @param list<string> $arguments
     * @return array<string, mixed>
     */
    public static function command(ArchiveFixture $archive, array $descriptor, array $arguments): array
    {
        return configuration_result(configuration_cli($archive, $descriptor, $arguments));
    }

    /**
     * Require that admitted work actually settles in the real Messenger/Durable worker.
     *
     * A queued receipt is not evidence of execution. Each drain is a fresh PHP
     * process, so this exercises application boot and durable delivery too.
     *
     * @return array<string, mixed>
     */
    public static function settle(ArchiveFixture $archive, array $descriptor, string $assignment): array
    {
        $deadline = microtime(true) + 35;
        do {
            $worker = configuration_cli($archive, $descriptor, ['operations:work', '--once']);
            Assert::assertSame(0, $worker['code'], $worker['output'] . $worker['errors']);
            $status = self::command($archive, $descriptor, ['operations:inspect', $assignment]);
            if (($status['state'] ?? null) === 'completed') {
                return $status;
            }

            Assert::assertNotSame('failed', $status['state'] ?? null, json_encode($status, JSON_THROW_ON_ERROR));
            usleep(150_000);
        } while (microtime(true) < $deadline);

        Assert::fail('Assignment never settled through the real Operations worker: ' . $assignment);
    }

    /**
     * Create a Stash, attach an existing Filesystem Input and discover one Item.
     *
     * The Input configuration remains Integration-owned; the new Stash association
     * and stable Item identity must be observable through the public Catalogue.
     *
     * @return array{stash: string, input: string, run: string, item: array<string, mixed>}
     */
    public static function discover(ArchiveFixture $archive, array $descriptor, string $source): array
    {
        self::command($archive, $descriptor, ['vault:init']);
        self::command($archive, $descriptor, ['integration:init']);
        $stash = self::command($archive, $descriptor, ['stash:create', 'Microtext acceptance']);
        Assert::assertNotEmpty($stash['id'] ?? null);
        $input = self::command($archive, $descriptor, ['input:create', 'filesystem', $source]);
        Assert::assertNotEmpty($input['id'] ?? null);
        $attached = self::command($archive, $descriptor, ['stash:input:attach', $stash['id'], $input['id']]);
        Assert::assertSame($stash['id'], $attached['stash_id'] ?? null);
        Assert::assertSame($input['id'], $attached['input_id'] ?? null);

        $discovery = self::command($archive, $descriptor, ['input:discover', $input['id']]);
        Assert::assertNotEmpty($discovery['assignment_id'] ?? null, 'Discovery must be admitted through Operations.');
        Assert::assertNotEmpty($discovery['run_id'] ?? null, 'Discovery must retain a durable run identifier.');
        self::settle($archive, $descriptor, $discovery['assignment_id']);

        $page = self::command($archive, $descriptor, ['stash:items', $stash['id']]);
        Assert::assertCount(1, $page['items'] ?? []);
        Assert::assertNull($page['next'] ?? null);
        $item = $page['items'][0];
        Assert::assertNotEmpty($item['uri'] ?? null, 'Catalogue must expose the canonical Item URI.');
        Assert::assertNotEmpty($item['object_id'] ?? null, 'Catalogue must expose the canonical OCFL Item identifier.');

        return ['stash' => $stash['id'], 'input' => $input['id'], 'run' => $discovery['run_id'], 'item' => $item];
    }

    /**
     * Submit one persisted discovery run for acquisition without invoking the legacy
     * synchronous acquisition coordinator from the test.
     */
    public static function acquire(ArchiveFixture $archive, array $descriptor, string $discoveryRun): string
    {
        $receipt = self::command($archive, $descriptor, ['input:acquire', $discoveryRun]);
        Assert::assertNotEmpty($receipt['assignment_id'] ?? null, 'Acquisition must be a durable Operations Job.');

        return $receipt['assignment_id'];
    }

    /**
     * Inspect raw OCFL inventories, exact Item pins and immutable Asset payload bytes.
     *
     * This is independent of Job state and of Stashd's public inspection service.
     * It detects a missing/changed Asset even if an Assignment says "completed".
     *
     * @return array{item: array<string, mixed>, pin: array{object_id: string, version: string}, payload: string}
     */
    public static function preserved(ArchiveFixture $archive, string $itemObjectId, string $expected): array
    {
        $root = $archive->path('vault');
        OcflInspector::assertRoot($root);
        $itemInventory = OcflInspector::inventoryFor($root, $itemObjectId)['inventory'];
        Assert::assertSame(['v1'], array_keys($itemInventory['versions']), 'A single acquisition must publish exactly one Item version.');
        OcflInspector::assertAcknowledgedHistory($root, $itemObjectId, 'v1');
        $record = TransactionProbe::json(OcflInspector::payloadPath($root, $itemObjectId, 'v1', 'item.json'));
        Assert::assertSame($itemObjectId, $record['object_id']);
        Assert::assertCount(1, $record['pins']);
        $pin = $record['pins'][0];
        Assert::assertSame('v1', $pin['version']);
        $assetInventory = OcflInspector::inventoryFor($root, $pin['object_id'])['inventory'];
        Assert::assertSame(['v1'], array_keys($assetInventory['versions']), 'Acquisition must not duplicate an Asset version.');
        OcflInspector::assertAcknowledgedHistory($root, $pin['object_id'], 'v1');
        $asset = TransactionProbe::json(OcflInspector::payloadPath($root, $pin['object_id'], 'v1', '.stashd/asset.json'));
        Assert::assertSame($record['uri'], $asset['item_uri']);
        Assert::assertSame($itemObjectId, $asset['item_object_id']);
        $payload = OcflInspector::payloadPath($root, $pin['object_id'], 'v1', 'data.bin');
        Assert::assertSame(strlen($expected), filesize($payload));
        Assert::assertSame(hash('sha256', $expected), hash_file('sha256', $payload));

        return ['item' => $record, 'pin' => $pin, 'payload' => $payload];
    }

    /**
     * Execute a standard-library generator/verifier without any Stashd implementation.
     *
     * A nonzero verifier status is returned for negative controls, whereas failed
     * generation is always an error in the test setup.
     *
     * @param list<string> $arguments
     */
    private static function python(array $arguments, ?int $expected = 0): int
    {
        $pipes = [];
        $process = proc_open(
            ['python3', 'tools/fixtures/microtext.py', ...$arguments],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Python 3 is required for the independent Microtext fixture.');
        }

        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($expected !== null) {
            Assert::assertSame($expected, $code, (string) $output . (string) $errors);
        }

        return $code;
    }
}
