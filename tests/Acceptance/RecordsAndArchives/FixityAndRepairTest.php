<?php

declare(strict_types=1);

use Tests\Support\RecordsAndArchives\OcflInspector;

test('fixity verification actually reads committed content and reports a healthy version', function (): void {
    $this->archive->run('vault.initialize');
    $asset = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/fixity/healthy',
        'asset_key' => 'master',
        'files' => ['data.bin' => $this->archive->input('healthy.bin', 'known original bytes')],
    ]);

    $result = $this->archive->run('fixity.verify', [
        'object_id' => $asset['object_id'],
        'version' => $asset['version'],
    ]);

    expect($result['healthy'])->toBeTrue()
        ->and($result['issues'])->toBe([]);
});

test('external corruption of canonical bytes cannot be reported as verified', function (): void {
    $this->archive->run('vault.initialize');
    $asset = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/fixity/corrupt',
        'asset_key' => 'master',
        'files' => ['data.bin' => $this->archive->input('corrupt.bin', 'uncorrupted')],
    ]);

    $path = OcflInspector::payloadPath(
        $this->archive->path('vault'),
        $asset['object_id'],
        $asset['version'],
        'data.bin',
    );
    file_put_contents($path, 'corruption');

    $result = $this->archive->run('fixity.verify', [
        'object_id' => $asset['object_id'],
        'version' => $asset['version'],
    ]);

    expect($result['healthy'])->toBeFalse()
        ->and($result['issues'])->not->toBe([]);
});

test('corrupt inventory checksums invalidate object verification even if payload survives', function (): void {
    $this->archive->run('vault.initialize');
    $asset = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/fixity/inventory',
        'asset_key' => 'master',
        'files' => ['data.bin' => $this->archive->input('inventory.bin', 'intact content')],
    ]);

    $inventory = OcflInspector::inventoryFor($this->archive->path('vault'), $asset['object_id']);
    $algorithm = is_file($inventory['path'] . '.sha512') ? 'sha512' : 'sha256';
    $length = $algorithm === 'sha512' ? 128 : 64;
    file_put_contents($inventory['path'] . '.' . $algorithm, str_repeat('0', $length) . "  inventory.json\n");

    $result = $this->archive->run('fixity.verify', ['object_id' => $asset['object_id']]);

    expect($result['healthy'])->toBeFalse()
        ->and($result['issues'])->not->toBe([]);
});

test('verified replica repair restores exact historical bytes and records repair history', function (): void {
    $this->archive->run('vault.initialize');
    $asset = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/fixity/repair',
        'asset_key' => 'master',
        'files' => ['data.bin' => $this->archive->input('repair.bin', 'authentic bytes')],
    ]);
    $replica = $this->archive->path('replica');
    $this->archive->run('replica.sync', ['replica' => $replica]);
    $before = $this->archive->run('history.events', ['object_id' => $asset['object_id']]);

    $path = OcflInspector::payloadPath(
        $this->archive->path('vault'),
        $asset['object_id'],
        $asset['version'],
        'data.bin',
    );
    file_put_contents($path, 'not authentic');

    $outcome = $this->archive->run('fixity.repair', [
        'object_id' => $asset['object_id'],
        'version' => $asset['version'],
        'replica' => $replica,
    ]);
    $after = $this->archive->run('history.events', ['object_id' => $asset['object_id']]);

    expect($outcome['repaired'])->toBeTrue()
        ->and(file_get_contents(OcflInspector::payloadPath(
            $this->archive->path('vault'),
            $asset['object_id'],
            $asset['version'],
            'data.bin',
        )))->toBe('authentic bytes')
        ->and(count($after['events']))->toBeGreaterThan(count($before['events']));
});

test('a corrupted replica is never accepted as repair authority', function (): void {
    $this->archive->run('vault.initialize');
    $asset = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/fixity/unrepairable',
        'asset_key' => 'master',
        'files' => ['data.bin' => $this->archive->input('unrepairable.bin', 'source truth')],
    ]);
    $replica = $this->archive->path('replica');
    $this->archive->run('replica.sync', ['replica' => $replica]);

    $main = OcflInspector::payloadPath($this->archive->path('vault'), $asset['object_id'], $asset['version'], 'data.bin');
    $copy = OcflInspector::payloadPath($replica, $asset['object_id'], $asset['version'], 'data.bin');
    file_put_contents($main, 'damaged main');
    file_put_contents($copy, 'damaged copy');

    $outcome = $this->archive->run('fixity.repair', [
        'object_id' => $asset['object_id'],
        'version' => $asset['version'],
        'replica' => $replica,
    ]);

    expect($outcome['repaired'])->toBeFalse()
        ->and($this->archive->run('fixity.verify', [
            'object_id' => $asset['object_id'],
            'version' => $asset['version'],
        ])['healthy'])->toBeFalse();
});

test('a repair cannot silently replace the archived original with different content', function (): void {
    $this->archive->run('vault.initialize');
    $asset = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/fixity/identity',
        'asset_key' => 'master',
        'files' => ['data.bin' => $this->archive->input('identity.bin', 'original truth')],
    ]);
    $replica = $this->archive->path('replica');
    $this->archive->run('replica.sync', ['replica' => $replica]);

    $canonical = OcflInspector::payloadPath($this->archive->path('vault'), $asset['object_id'], $asset['version'], 'data.bin');
    file_put_contents($canonical, 'wrong replacement');
    $this->archive->run('fixity.repair', [
        'object_id' => $asset['object_id'],
        'version' => $asset['version'],
        'replica' => $replica,
    ]);

    $evidence = $this->archive->run('fixity.verify', [
        'object_id' => $asset['object_id'],
        'version' => $asset['version'],
    ]);

    expect($evidence['healthy'])->toBeTrue()
        ->and(hash_file('sha256', OcflInspector::payloadPath(
            $this->archive->path('vault'),
            $asset['object_id'],
            $asset['version'],
            'data.bin',
        )))->toBe(hash('sha256', 'original truth'));
});
