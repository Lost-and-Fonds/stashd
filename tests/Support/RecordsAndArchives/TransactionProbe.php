<?php

namespace Tests\Support\RecordsAndArchives;

use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * Supervise disposable PHP writers and verify their receipts against physical OCFL evidence.
 *
 * No transaction state is implemented here. An optional adapter must invoke real
 * application services; the default child exposes only existing publication probes.
 */
final class TransactionProbe
{
    /**
     * Start a fresh child with private logs and an optional controlled crash boundary.
     *
     * The caller must finish the child, including after assertion failures.
     *
     * @param array<string, mixed> $request
     * @return array{process: resource, prefix: string, boundary: string}
     */
    public static function start(string $workspace, array $request, string $boundary = '', string $pause = ''): array
    {
        $prefix = $workspace . '/process-' . bin2hex(random_bytes(8));
        $request['root'] = $workspace . '/vault';
        file_put_contents($prefix . '.json', json_encode($request, JSON_THROW_ON_ERROR));
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/transaction-process.php', $prefix, $boundary, $pause],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $prefix . '.out', 'w'], 2 => ['file', $prefix . '.err', 'w']],
            $pipes,
            env_vars: [
                ...getenv(),
                'TMPDIR' => $workspace,
                'DATABASE_URL' => 'pgsql:host=127.0.0.1;port=1;dbname=unavailable',
                'DATABASE_HOST' => '127.0.0.1',
                'DATABASE_PORT' => '1',
                'DATABASE_DATABASE' => 'unavailable',
                'DATABASE_USERNAME' => 'test',
                'DATABASE_PASSWORD' => bin2hex(random_bytes(16)),
            ],
        );
        if ($process === false) {
            throw new RuntimeException('Could not start transaction probe.');
        }

        return compact('process', 'prefix', 'boundary');
    }

    /**
     * Require an actual authority transition while the supervised child remains paused.
     *
     * The adapter must report contention only after a denied claim, never before
     * attempting it. The caller owns cleanup if this assertion fails.
     */
    private static function paused(array $child, string $boundary): void
    {
        $deadline = microtime(true) + 5;
        while (! is_file($child['prefix'] . '.paused') && microtime(true) < $deadline) {
            $status = proc_get_status($child['process']);
            Assert::assertTrue($status['running'], 'Child exited before ' . $boundary . ': ' . file_get_contents($child['prefix'] . '.err'));
            usleep(10_000);
        }

        Assert::assertFileExists($child['prefix'] . '.paused', 'Missing authority boundary: ' . $boundary);
        Assert::assertSame($boundary, file_get_contents($child['prefix'] . '.paused'));
    }

    /**
     * Hold one owner until a second writer proves a denied authority claim.
     *
     * Resume or kill the owner before releasing the contender. Both children
     * are always reaped; missing hooks and publication during contention fail.
     * This supervises the real boundary and implements no commit authority.
     */
    public static function contend(string $workspace, array $owner, array $contender, bool $killOwner): array
    {
        $children = [];
        try {
            $a = self::start($workspace, $owner, pause: 'authority-held');
            $children['a'] = $a;
            self::paused($a, 'authority-held');
            $before = self::snapshot($workspace . '/vault');
            $b = self::start($workspace, $contender, pause: 'authority-contended');
            $children['b'] = $b;
            self::paused($b, 'authority-contended');
            Assert::assertSame('', file_get_contents($a['prefix'] . '.out'));
            Assert::assertSame('', file_get_contents($b['prefix'] . '.out'));
            Assert::assertStringNotContainsString('authority-held', file_get_contents($b['prefix'] . '.events'));
            Assert::assertSame($before, self::snapshot($workspace . '/vault'), 'Contender published while another writer owned authority.');
            if ($killOwner) {
                proc_terminate($a['process'], 9);
            } else {
                file_put_contents($a['prefix'] . '.resume', 'resume');
            }

            $first = self::finish($a);
            unset($children['a']);
            if ($killOwner) {
                Assert::assertSame(9, $first['signal']);
                Assert::assertSame('', $first['out']);
                $first = ['status' => 'owner-killed'];
            } else {
                Assert::assertSame(0, $first['exit'], $first['err']);
                $first = json_decode($first['out'], true, flags: JSON_THROW_ON_ERROR);
            }

            file_put_contents($b['prefix'] . '.resume', 'resume');
            $second = self::finish($b);
            unset($children['b']);
            Assert::assertSame(0, $second['exit'], $second['err']);

            return [$first, json_decode($second['out'], true, flags: JSON_THROW_ON_ERROR)];
        } finally {
            foreach (array_filter($children, static fn (array $child): bool => is_resource($child['process'])) as $child) {
                proc_terminate($child['process'], 9);
                proc_close($child['process']);
            }
        }
    }

    /**
     * Reap the child within a deadline, killing it only after observing the requested boundary.
     *
     * Timeout and a missing boundary are failures, never successful crash proofs.
     * Unreadable stdout or stderr fails explicitly after the process is reaped.
     *
     * @param array{process: resource, prefix: string, boundary: string} $child
     * @return array{exit: int, signal: int, out: string, err: string}
     */
    public static function finish(array $child): array
    {
        $deadline = microtime(true) + 15;
        $killed = false;
        do {
            if ($child['boundary'] !== '' && is_file($child['prefix'] . '.boundary')) {
                proc_terminate($child['process'], 9);
                $killed = true;
            }

            $status = proc_get_status($child['process']);
            if (! $status['running']) {
                break;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        if ($status['running']) {
            proc_terminate($child['process'], 9);
        }

        $closed = proc_close($child['process']);
        $out = file_get_contents($child['prefix'] . '.out');
        $err = file_get_contents($child['prefix'] . '.err');
        Assert::assertIsString($out, 'Could not read transaction child stdout log: ' . $child['prefix'] . '.out');
        Assert::assertIsString($err, 'Could not read transaction child stderr log: ' . $child['prefix'] . '.err');
        $result = [
            'exit' => $status['exitcode'] >= 0 ? $status['exitcode'] : $closed,
            'signal' => $status['termsig'],
            'out' => $out,
            'err' => $err,
        ];
        Assert::assertFalse($status['running'], 'Transaction child timed out: ' . $result['err']);
        if ($child['boundary'] !== '') {
            Assert::assertTrue($killed, 'Requested boundary was never reached: ' . $result['err']);
            Assert::assertSame($child['boundary'], file_get_contents($child['prefix'] . '.boundary'));
            Assert::assertSame(9, $result['signal']);
            Assert::assertSame('', $result['out'], 'A killed transaction must not have emitted a success receipt.');
        }

        return $result;
    }

    /**
     * Require a successful process and a structured semantic result.
     *
     * Exit 78 identifies an absent implementation; it deliberately fails acceptance.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function run(string $workspace, array $request): array
    {
        $result = self::finish(self::start($workspace, $request));
        Assert::assertSame(0, $result['exit'], $result['err'] . $result['out']);
        $decoded = json_decode($result['out'], true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsArray($decoded, 'Process result must be a JSON object.');

        return $decoded;
    }

    /**
     * Independently require an exact receipt and all its referenced preserved bytes.
     *
     * This checks native inventories, sidecars, historical records and requested
     * source digests, without trusting application read or inspection results.
     *
     * @param array{status: string, transaction_id: string, item: array{object_id: string, version: string}, ...} $receipt
     * @param array<string, string> $expected
     */
    public static function committed(string $root, array $receipt, string $transaction, array $expected): void
    {
        Assert::assertSame('complete', $receipt['status']);
        Assert::assertSame($transaction, $receipt['transaction_id']);
        $item = $receipt['item'];
        $inventory = OcflInspector::inventoryFor($root, $item['object_id'])['inventory'];
        Assert::assertArrayHasKey($item['version'], $inventory['versions']);
        OcflInspector::assertAcknowledgedHistory($root, $item['object_id'], $inventory['head']);
        $record = self::json(OcflInspector::payloadPath($root, $item['object_id'], $item['version'], 'item.json'));
        Assert::assertSame($item['object_id'], $record['object_id']);
        Assert::assertSame('urn:example:transaction:item', $record['uri']);
        $pins = $record['pins'];
        Assert::assertIsArray($pins);
        Assert::assertCount(count($expected), $pins);
        foreach ($expected as $key => $bytes) {
            $matches = [];
            foreach ($pins as $pin) {
                Assert::assertIsArray($pin);
                Assert::assertIsString($pin['object_id']);
                Assert::assertIsString($pin['version']);
                $inventory = OcflInspector::inventoryFor($root, $pin['object_id'])['inventory'];
                Assert::assertArrayHasKey($pin['version'], $inventory['versions']);
                OcflInspector::assertAcknowledgedHistory($root, $pin['object_id'], $inventory['head']);
                $asset = self::json(OcflInspector::payloadPath($root, $pin['object_id'], $pin['version'], '.stashd/asset.json'));
                if ($asset['asset_key'] === $key) {
                    Assert::assertSame($record['uri'], $asset['item_uri']);
                    Assert::assertSame($item['object_id'], $asset['item_object_id']);
                    $matches[] = file_get_contents(OcflInspector::payloadPath($root, $pin['object_id'], $pin['version'], 'data.bin'));
                }
            }

            Assert::assertSame([$bytes], $matches, 'Missing, duplicate or wrong exact Asset pin: ' . $key);
        }
    }

    /**
     * Read physical evidence as a JSON object, failing explicitly on absent or unreadable bytes.
     *
     * Reject lists and top-level keys that PHP coerces into integers; nested values
     * retain their existing associative decoding and JSON errors propagate unchanged.
     *
     * @return array<string, mixed>
     */
    public static function json(string $path): array
    {
        Assert::assertFileExists($path);
        $raw = file_get_contents($path);
        Assert::assertIsString($raw);
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsArray($decoded);
        // Successful parsing makes the root token decisive, including for {} versus [].
        Assert::assertSame('{', ltrim($raw)[0] ?? null, 'Evidence must contain a JSON object, not a list.');
        $object = [];
        foreach ($decoded as $key => $value) {
            Assert::assertIsString($key, 'JSON object keys must remain strings when decoded by PHP.');
            $object[$key] = $value;
        }

        return $object;
    }

    /**
     * Hash every Vault file to detect duplicate publication, mutation or evidence deletion.
     *
     * @return array<string, string>
     */
    public static function snapshot(string $root): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }

            $files[substr($file->getPathname(), strlen($root))] = hash_file('sha512', $file->getPathname());
        }

        ksort($files);

        return $files;
    }
}
