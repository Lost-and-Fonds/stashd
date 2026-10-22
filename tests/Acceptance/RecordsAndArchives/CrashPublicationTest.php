<?php

use Tests\Support\RecordsAndArchives\OcflInspector;

/**
 * Run a real archival child process with a selected abrupt-death boundary.
 *
 * Collects stdout, stderr, exit status and termination signal, closing pipes
 * and reaping the child. A 20-second timeout kills it and raises RuntimeException.
 */
function crash_process(string $root, string $operation, string $kind, string $value = '', string $boundary = ''): array
{
    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__, 2) . '/Support/RecordsAndArchives/crash-process.php', $root, $operation, $kind, $value],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        env_vars: [...getenv(), 'CRASH_BOUNDARY' => $boundary, 'TMPDIR' => dirname($root)],
    );
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $error = '';
    $deadline = microtime(true) + 20;
    do {
        $output .= stream_get_contents($pipes[1]);
        $error .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (! $status['running']) {
            break;
        }
        usleep(10_000);
    } while (microtime(true) < $deadline);
    if ($status['running']) {
        proc_terminate($process, 9);
    }
    $output .= stream_get_contents($pipes[1]);
    $error .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($status['running']) {
        throw new RuntimeException('Publication subprocess timed out: ' . $error);
    }
    return ['output' => $output, 'error' => $error, 'exit' => $status['exitcode'] >= 0 ? $status['exitcode'] : $exit, 'signal' => $status['termsig']];
}

/**
 * Hash every physical file under a disposable Vault root for before-and-after comparisons.
 *
 * Sorts relative paths so tests can detect changed bytes without consulting
 * application services or relying on directory enumeration order.
 */
function crash_snapshot(string $root): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $files[substr($file->getPathname(), strlen($root))] = hash_file('sha512', $file->getPathname());
    }
    ksort($files);
    return $files;
}

test('abrupt publication death preserves committed history and reports physical evidence', function (string $kind, bool $update, string $boundary): void {
    $this->archive->run('vault.initialize');
    $root = $this->archive->path('vault');
    $first = $kind === 'item' ? 'first' : $this->archive->input('first.bin', 'first payload');
    $next = $kind === 'item' ? 'next' : $this->archive->input('next.bin', 'next payload');
    if ($update) {
        $created = crash_process($root, 'write', $kind, $first);
        $this->assertSame(0, $created['exit'], $created['error'] . $created['output']);
        $second = $kind === 'item' ? 'second' : $this->archive->input('second.bin', 'second payload');
        $created = crash_process($root, 'write', $kind, $second);
        $this->assertSame(0, $created['exit'], $created['error'] . $created['output']);
    }
    $before = crash_snapshot($root);
    $crashed = crash_process($root, 'write', $kind, $next, $boundary);
    $this->assertStringContainsString('CRASH:' . $boundary, $crashed['error'], json_encode($crashed));
    expect([$crashed['signal'], $crashed['exit']])->toContain(9);
    expect($crashed['output'])->toBe('');
    $after = crash_snapshot($root);
    foreach ($before as $path => $digest) {
        if (! preg_match('~/v\d+/~', $path)) {
            continue;
        }

        expect($after[$path] ?? null)->toBe($digest);
    }
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $inspection = crash_process($root, 'inspect', $kind);
        $this->assertSame(0, $inspection['exit'], $inspection['error'] . $inspection['output']);
        $state = json_decode($inspection['output'], true, flags: JSON_THROW_ON_ERROR);
        $committed = in_array($boundary, ['root-sidecar-published', 'before-cleanup'], true);
        expect($state['head'])->toBe($committed ? ($update ? 'v3' : 'v1') : ($update ? 'v2' : null));
        if (! $committed) {
            expect($state['issues'])->not->toBeEmpty();
        }
        if ($committed) {
            expect($state['records'][$state['head']][$kind === 'item' ? 'metadata' : 'files'])
                ->toBe(
                    $kind === 'item'
                        ? ['revision' => 'next']
                        : ['payload.bin' => ['size' => strlen('next payload'), 'algorithm' => 'sha512', 'digest' => hash('sha512', 'next payload')]],
                );
        } else {
            expect(crash_process($root, 'read-version', $kind, $update ? 'v3' : 'v1')['exit'])->not->toBe(0);
        }
        if ($state['head'] !== null) {
            OcflInspector::assertAcknowledgedHistory($root, $state['object_id'], $state['head']);
        }
        if ($update) {
            expect($state['records']['v2'][$kind === 'item' ? 'metadata' : 'version'])->toBe($kind === 'item' ? ['revision' => 'second'] : 'v2');
            expect($state['record'][$kind === 'item' ? 'metadata' : 'version'])->toBe($kind === 'item' ? ['revision' => 'first'] : 'v1');
        }
        expect(crash_snapshot($root))->toBe($after);
    }
    // No retry may remove evidence or reuse a stranded version number.
    if ($state['issues'] !== []) {
        $retry = crash_process($root, 'write', $kind, $next);
        expect($retry['exit'])->not->toBe(0);
        expect(crash_snapshot($root))->toBe($after);
    }
})->with(['item', 'asset'])->with([false, true])->with([
    'content-partial',
    'content-complete',
    'version-inventory-partial',
    'version-ready',
    'version-published',
    'root-inventory-partial',
    'root-inventory-published',
    'root-sidecar-partial',
    'root-sidecar-published',
    'before-cleanup',
]);

test('the production inspection command reports interrupted publication without repairing it', function (): void {
    $this->archive->run('vault.initialize');
    $root = $this->archive->path('vault');
    $created = crash_process($root, 'write', 'item', 'first');
    expect($created['exit'])->toBe(0);
    $created = json_decode($created['output'], true, flags: JSON_THROW_ON_ERROR);
    $killed = crash_process($root, 'write', 'item', 'second', 'root-inventory-published');
    expect([$killed['signal'], $killed['exit']])->toContain(9);
    $state = json_decode(crash_process($root, 'inspect', 'item')['output'], true, flags: JSON_THROW_ON_ERROR);
    $before = crash_snapshot($root);
    $log = $this->archive->path('cli.log');
    $process = proc_open(
        [PHP_BINARY, 'tempest', 'vault:inspect-object', $state['object_id'], '--no-interaction'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
        $pipes,
        dirname(__DIR__, 3),
        [
            ...getenv(),
            'VAULT_ROOT' => $root,
            'TMPDIR' => dirname($root),
            'ENVIRONMENT' => 'local',
            'SIGNING_KEY' => base64_encode(str_repeat('s', 32)),
            'ENCRYPTION_KEY' => base64_encode(str_repeat('e', 32)),
        ],
    );
    $exit = proc_close($process);
    $output = file_get_contents($log);
    $this->assertSame(0, $exit, $output);
    expect($output)->toContain('"head":"v1"')->toContain('Root inventory differs')->toContain('v2');
    expect(crash_snapshot($root))->toBe($before);
});

test('inspection rejects damaged acknowledged inventories and payloads and preserves all remaining evidence', function (string $damage): void {
    $this->archive->run('vault.initialize');
    $root = $this->archive->path('vault');
    $created = crash_process($root, 'write', 'item', 'first');
    expect($created['exit'])->toBe(0);
    $state = json_decode(crash_process($root, 'inspect', 'item')['output'], true, flags: JSON_THROW_ON_ERROR);
    $found = OcflInspector::inventoryFor($root, $state['object_id']);
    $path =
        dirname($found['path']) . '/v1/' . match ($damage) {
            'inventory' => 'inventory.json',
            'sidecar' => 'inventory.json.sha512',
            'payload' => 'content/item.json',
        };
    file_put_contents($path, 'incomplete bytes');
    $before = crash_snapshot($root);
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $inspection = crash_process($root, 'inspect', 'item');
        expect($inspection['exit'])->toBe(0);
        $state = json_decode($inspection['output'], true, flags: JSON_THROW_ON_ERROR);
        expect($state['head'])->toBeNull();
        expect($state['issues'])->not->toBeEmpty();
        expect(crash_snapshot($root))->toBe($before);
    }
    expect(crash_process($root, 'write', 'item', 'retry')['exit'])->not->toBe(0);
    expect(crash_snapshot($root))->toBe($before);
})->with(['inventory', 'sidecar', 'payload']);
