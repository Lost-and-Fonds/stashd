<?php

declare(strict_types=1);

use Tests\Support\RecordsAndArchives\OcflInspector;

test('the local archival storage adapter commits and reopens two OCFL versions', function (): void {
    $this->archive->run('vault.initialize');

    $receipt = $this->archive->run('storage.ocfl_round_trip');
    $objectId = $receipt['object_id'];

    expect($receipt['versions'])
        ->toBe(['v1', 'v2'])
        ->and($receipt['listed_files'])
        ->toContain('v1/content/example.txt')
        ->toContain('v2/content/example.txt');

    $found = OcflInspector::inventoryFor($this->archive->path('vault'), $objectId);

    expect($found['inventory']['head'])->toBe('v2');
    OcflInspector::assertInventorySidecar($found['path']);

    foreach (['v1' => 'original bytes', 'v2' => 'revised bytes'] as $version => $expected) {
        $physical = OcflInspector::payloadPath(
            $this->archive->path('vault'),
            $objectId,
            $version,
            'example.txt',
        );

        expect(file_get_contents($physical))->toBe($expected);
    }

    // Reopen with another Tempest container, not a cached OCFL object.
    $this->archive->run('vault.open');
    expect(OcflInspector::inventoryFor($this->archive->path('vault'), $objectId)['inventory']['head'])->toBe('v2');
});

test('the actual configured backend, not the logical Vault, reports storage capabilities', function (): void {
    $this->archive->run('vault.initialize');

    $local = $this->archive->run('storage.probe', [
        'backend' => 'local',
        'capabilities' => ['atomic_no_replace', 'conditional_write', 'hardlink'],
    ]);
    $limited = $this->archive->run('storage.probe', [
        'backend' => 'restricted-local',
        'capabilities' => ['atomic_no_replace', 'conditional_write', 'hardlink'],
    ]);

    foreach ([$local, $limited] as $result) {
        foreach (['atomic_no_replace', 'conditional_write', 'hardlink'] as $capability) {
            expect($result['supports'][$capability])->toBeBool();
        }
    }

    expect($limited['supports']['hardlink'])->toBeFalse();
});

test('a backend lacking hardlink support can still preserve independent immutable bytes', function (): void {
    $this->archive->run('vault.initialize', [
        'backend' => 'restricted-local',
    ]);
    $input = $this->archive->input('portable.bin', 'portable original');
    $asset = $this->archive->run('asset.preserve', [
        'backend' => 'restricted-local',
        'item_uri' => 'https://example.test/storage/portable',
        'asset_key' => 'master',
        'files' => ['data.bin' => $input],
    ]);

    file_put_contents($input, 'overwritten input');

    $path = OcflInspector::payloadPath(
        $this->archive->path('vault'),
        $asset['object_id'],
        $asset['version'],
        'data.bin',
    );

    expect(file_get_contents($path))->toBe('portable original');
});

test('large input files remain byte-exact across archival streaming and versioned readback', function (): void {
    $this->archive->run('vault.initialize');
    $payload = str_repeat("large-stream\0\n", 1024 * 512);
    $source = $this->archive->input('large.bin', $payload);
    $expectedHash = hash_file('sha256', $source);
    unset($payload);

    $asset = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/storage/large',
        'asset_key' => 'master',
        'files' => ['large.bin' => $source],
    ]);

    $path = OcflInspector::payloadPath(
        $this->archive->path('vault'),
        $asset['object_id'],
        $asset['version'],
        'large.bin',
    );

    expect(hash_file('sha256', $path))->toBe($expectedHash)->and(filesize($path))->toBe(filesize($source));
});

test('physical storage integrity is independent of the availability of catalogue projections', function (): void {
    $this->archive->run('vault.initialize');
    $asset = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/storage/projection',
        'asset_key' => 'master',
        'files' => ['data.bin' => $this->archive->input('projection.bin', 'archive truth')],
    ]);

    $this->archive->run('catalogue.drop_projection');
    $verified = $this->archive->run('fixity.verify', [
        'object_id' => $asset['object_id'],
        'version' => $asset['version'],
    ]);

    expect($verified['healthy'])
        ->toBeTrue()
        ->and(file_get_contents(OcflInspector::payloadPath(
            $this->archive->path('vault'),
            $asset['object_id'],
            $asset['version'],
            'data.bin',
        )))
        ->toBe('archive truth');
});
