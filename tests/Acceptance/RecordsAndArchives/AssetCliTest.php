<?php

use Tests\Support\RecordsAndArchives\OcflInspector;

/**
 * Boot a fresh real Tempest CLI process against the supplied disposable Vault.
 *
 * Uses testing mode and a 32 MB memory limit without an acceptance driver.
 * Closes output pipes and returns the exit code, stdout and stderr; startup
 * failure raises RuntimeException.
 */
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

test('Item CLI reconstructs exact Asset pins and binary bytes across fresh applications', function (): void {
    $root = $this->archive->path('vault');
    $uri = 'urn:test:item-cli';
    expect(asset_cli($root, ['vault:init'])['code'])->toBe(0);
    $first = asset_cli($root, ['item:commit', $uri, '{"title":"Known"}']);
    expect($first['code'])->toBe(0, $first['output'] . $first['errors']);
    $item = json_decode(trim($first['output']), true, flags: JSON_THROW_ON_ERROR);
    $source = $this->archive->input('item-source', "first\0binary\xff");
    $digest1 = hash_file('sha512', $source);
    $import = asset_cli($root, ['asset:import', $uri, 'master', 'binary', $source]);
    expect($import['code'])->toBe(0, $import['output'] . $import['errors']);
    $asset1 = json_decode(trim($import['output']), true, flags: JSON_THROW_ON_ERROR);
    $pins1 = [['object_id' => $asset1['object_id'], 'version' => 'v1']];
    $second = asset_cli($root, ['item:commit', $uri, '{"title":"First"}', json_encode($pins1, JSON_THROW_ON_ERROR)]);
    expect($second['code'])->toBe(0, $second['output'] . $second['errors']);
    file_put_contents($source, "second\0binary\xfe");
    $digest2 = hash_file('sha512', $source);
    $import = asset_cli($root, ['asset:import', $uri, 'master', 'binary', $source]);
    expect($import['code'])->toBe(0);
    $pins2 = [['object_id' => $asset1['object_id'], 'version' => 'v2']];
    $third = asset_cli($root, ['item:commit', $uri, '{"title":"Second"}', json_encode($pins2, JSON_THROW_ON_ERROR)]);
    expect($third['code'])->toBe(0, $third['output'] . $third['errors']);
    unlink($source);
    foreach ([
        ['v2', $pins1, $digest1],
        ['v3', $pins2, $digest2],
    ] as [$version, $pins, $digest]) {
        $inspect = asset_cli($root, ['item:inspect', $item['object_id'], $version]);
        expect($inspect['code'])->toBe(0, $inspect['output'] . $inspect['errors']);
        $record = json_decode(trim($inspect['output']), true, flags: JSON_THROW_ON_ERROR);
        expect($record['pins'])->toBe($pins)->and($record['ocfl_version'])->toBe($version);
        $destination = $this->archive->path('item-export-' . $version);
        $pin = $record['pins'][0];
        $export = asset_cli($root, ['asset:export', $pin['object_id'], $pin['version'], 'binary', $destination]);
        expect($export['code'])
            ->toBe(0, $export['output'] . $export['errors'])
            ->and(hash_file('sha512', $destination))
            ->toBe($digest);
    }
    $latest = asset_cli($root, ['item:inspect', $item['object_id']]);
    expect($latest['code'])
        ->toBe(0)
        ->and(json_decode(trim($latest['output']), true, flags: JSON_THROW_ON_ERROR)['pins'])
        ->toBe($pins2);
    $before = OcflInspector::inventoryFor($root, $item['object_id']);
    foreach (['null', '{}', '[{"version":"latest"}]', 'malformed'] as $invalid) {
        expect(asset_cli($root, ['item:commit', $uri, '{}', $invalid])['code'])->not->toBe(0);
        expect(OcflInspector::inventoryFor($root, $item['object_id'])['inventory'])->toBe($before['inventory']);
    }
});
