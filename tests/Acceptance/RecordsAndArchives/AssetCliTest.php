<?php

use Tests\Support\RecordsAndArchives\OcflInspector;

/** Every invocation boots a fresh real Tempest application, with no DB or acceptance driver. */
function asset_cli(string $root, array $arguments): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=32M', 'tempest', ...$arguments, '--no-interaction'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__, 3),
        [...getenv(), 'VAULT_ROOT' => $root, 'ENVIRONMENT' => 'testing'],
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start Tempest CLI.');
    }
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'output' => $output, 'errors' => $errors];
}

test('real CLI preserves and exports historical binary bytes in fresh processes under a 32 MiB PHP limit', function (): void {
    $root = $this->archive->path('vault');
    expect(asset_cli($root, ['vault:init'])['code'])->toBe(0);
    $source = $this->archive->input('large.bin', '');
    $stream = fopen($source, 'wb');
    $chunk = random_bytes(1024 * 1024);
    for ($i = 0; $i < 64; $i++) {
        fwrite($stream, $chunk);
    }
    fclose($stream);
    unset($chunk);
    $digest = hash_file('sha512', $source);
    $first = asset_cli($root, ['asset:import', 'urn:test:cli', 'master', 'binary.bin', $source]);
    expect($first['code'])->toBe(0, $first['output'] . $first['errors']);
    $receipt = json_decode(trim($first['output']), true, flags: JSON_THROW_ON_ERROR);
    expect($receipt['files']['binary.bin']['size'])
        ->toBe(64 * 1024 * 1024)
        ->and($receipt['files']['binary.bin']['digest'])
        ->toBe($digest);
    file_put_contents($source, "revised\0bytes");
    $second = asset_cli($root, ['asset:import', 'urn:test:cli', 'master', 'binary.bin', $source]);
    expect($second['code'])->toBe(0, $second['output'] . $second['errors']);
    unlink($source);
    $destination = $this->archive->path('export-v1.bin');
    $export = asset_cli($root, ['asset:export', $receipt['object_id'], 'v1', 'binary.bin', $destination]);
    expect($export['code'])
        ->toBe(0, $export['output'] . $export['errors'])
        ->and(hash_file('sha512', $destination))
        ->toBe($digest);
    expect(asset_cli($root, ['asset:export', $receipt['object_id'], 'v1', 'binary.bin', $destination])['code'])
        ->not
        ->toBe(0)
        ->and(hash_file('sha512', $destination))
        ->toBe($digest);
    $secondDestination = $this->archive->path('export-v2.bin');
    $export = asset_cli($root, ['asset:export', $receipt['object_id'], 'v2', 'binary.bin', $secondDestination]);
    expect($export['code'])
        ->toBe(0, $export['output'] . $export['errors'])
        ->and(file_get_contents($secondDestination))
        ->toBe("revised\0bytes");
    $inspect = asset_cli($root, ['asset:inspect', $receipt['object_id'], 'v1']);
    expect($inspect['code'])
        ->toBe(0)
        ->and(json_decode(trim($inspect['output']), true, flags: JSON_THROW_ON_ERROR)['files']['binary.bin']['digest'])
        ->toBe($digest);
    $found = OcflInspector::inventoryFor($root, $receipt['object_id']);
    OcflInspector::assertInventorySidecar($found['path']);
    expect(array_keys($found['inventory']['versions']))->toBe(['v1', 'v2']);
})->group('asset-cli');
