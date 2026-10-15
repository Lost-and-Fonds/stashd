<?php

declare(strict_types=1);

use Tests\Support\RecordsAndArchives\ArchivesRejected;
use Tests\Support\RecordsAndArchives\OcflInspector;

test('preservation adopts the original input bytes into a valid OCFL object', function (): void {
    $bytes = "first archival payload\0with binary data\n";
    $source = $this->archive->input('original.bin', $bytes);
    $this->archive->run('vault.initialize');

    $receipt = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/records/1',
        'asset_key' => 'master',
        'files' => ['master.bin' => $source],
    ]);

    $found = OcflInspector::inventoryFor($this->archive->path('vault'), $receipt['object_id']);
    OcflInspector::assertInventorySidecar($found['path']);

    $physical = OcflInspector::payloadPath(
        $this->archive->path('vault'),
        $receipt['object_id'],
        $receipt['version'],
        'master.bin',
    );

    expect(file_get_contents($physical))->toBe($bytes)
        ->and($this->archive->run('asset.read', [
            'object_id' => $receipt['object_id'],
            'version' => $receipt['version'],
            'logical_path' => 'master.bin',
        ])['bytes'])->toBe($bytes);
});

test('two versions of one Asset preserve both original byte sequences', function (): void {
    $this->archive->run('vault.initialize');

    $first = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/records/2',
        'asset_key' => 'master',
        'files' => ['video.bin' => $this->archive->input('version1.bin', 'old bytes')],
    ]);

    $second = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/records/2',
        'asset_key' => 'master',
        'files' => ['video.bin' => $this->archive->input('version2.bin', 'new bytes')],
    ]);

    expect($second['object_id'])->toBe($first['object_id'])
        ->and($second['version'])->not->toBe($first['version']);

    foreach ([[$first, 'old bytes'], [$second, 'new bytes']] as [$receipt, $expected]) {
        $path = OcflInspector::payloadPath(
            $this->archive->path('vault'),
            $receipt['object_id'],
            $receipt['version'],
            'video.bin',
        );

        expect(file_get_contents($path))->toBe($expected);
    }
});

test('identical bytes from two logical Asset keys do not merge their identities', function (): void {
    $this->archive->run('vault.initialize');
    $input = $this->archive->input('same.bin', 'shared content');

    $first = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/records/3',
        'asset_key' => 'original',
        'files' => ['data.bin' => $input],
    ]);
    $second = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/records/3',
        'asset_key' => 'alternate',
        'files' => ['data.bin' => $input],
    ]);

    expect($second['object_id'])->not->toBe($first['object_id']);
});

test('a multi-file Asset version commits all named files as one coherent version', function (): void {
    $this->archive->run('vault.initialize');

    $receipt = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/records/4',
        'asset_key' => 'multifile',
        'files' => [
            'segments/a.bin' => $this->archive->input('a.bin', 'A'),
            'segments/b.bin' => $this->archive->input('b.bin', 'B'),
        ],
        'scope' => ['state' => 'closed', 'expected' => ['segments/a.bin', 'segments/b.bin']],
    ]);

    foreach (['segments/a.bin' => 'A', 'segments/b.bin' => 'B'] as $path => $bytes) {
        expect(file_get_contents(OcflInspector::payloadPath(
            $this->archive->path('vault'),
            $receipt['object_id'],
            $receipt['version'],
            $path,
        )))->toBe($bytes);
    }
});

test('modifying the acquisition source after commit cannot change preserved bytes', function (): void {
    $this->archive->run('vault.initialize');
    $source = $this->archive->input('mutable-source.bin', 'original');

    $receipt = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/records/5',
        'asset_key' => 'master',
        'files' => ['master.bin' => $source],
    ]);

    file_put_contents($source, 'replaced');

    $path = OcflInspector::payloadPath(
        $this->archive->path('vault'),
        $receipt['object_id'],
        $receipt['version'],
        'master.bin',
    );

    expect(file_get_contents($path))->toBe('original');
});

test('a traversal-style logical path cannot escape an archived Asset', function (): void {
    $this->archive->run('vault.initialize');

    expect(fn () => $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/records/6',
        'asset_key' => 'master',
        'files' => ['../../outside.bin' => $this->archive->input('malicious.bin', 'bad')],
    ]))->toThrow(ArchivesRejected::class);

    expect(file_exists($this->archive->path('outside.bin')))->toBeFalse();
});

test('a missing acquisition source cannot yield a successful preservation receipt', function (): void {
    $this->archive->run('vault.initialize');

    expect(fn () => $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/records/7',
        'asset_key' => 'master',
        'files' => ['master.bin' => $this->archive->path('missing.bin')],
    ]))->toThrow(ArchivesRejected::class);
});

test('different Items cannot silently collapse distinct Asset identities', function (): void {
    $this->archive->run('vault.initialize');
    $input = $this->archive->input('repeat.bin', 'same bytes');

    $first = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/records/8',
        'asset_key' => 'master',
        'files' => ['data.bin' => $input],
    ]);
    $second = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/records/9',
        'asset_key' => 'master',
        'files' => ['data.bin' => $input],
    ]);

    expect($second['object_id'])->not->toBe($first['object_id']);
});

test('Asset versions survive a fresh Vault open rather than relying on memory', function (): void {
    $this->archive->run('vault.initialize');
    $receipt = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/records/10',
        'asset_key' => 'master',
        'files' => ['data.bin' => $this->archive->input('persist.bin', 'still here')],
    ]);
    $this->archive->run('vault.open');

    $read = $this->archive->run('asset.read', [
        'object_id' => $receipt['object_id'],
        'version' => $receipt['version'],
        'logical_path' => 'data.bin',
    ]);

    expect($read['bytes'])->toBe('still here');
});
