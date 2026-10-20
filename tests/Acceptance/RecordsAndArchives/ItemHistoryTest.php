<?php

declare(strict_types=1);

use Tests\Support\RecordsAndArchives\ArchivesRejected;

test('an Item without archived content remains a durable discoverable archival record', function (): void {
    $this->archive->run('vault.initialize');
    $record = $this->archive->run('item.record', [
        'uri' => 'https://example.test/items/metadata-only',
        'pins' => [],
        'metadata' => ['title' => 'Known but not acquired'],
    ]);
    $this->archive->run('vault.open');

    $read = $this->archive->run('item.read', [
        'object_id' => $record['object_id'],
        'version' => $record['version'],
    ]);

    expect($read['uri'])->toBe('https://example.test/items/metadata-only')
        ->and($read['pins'])->toBe([])
        ->and($read['metadata']['title'])->toBe('Known but not acquired');
});

test('an Item pins an exact Asset version rather than an unqualified latest revision', function (): void {
    $this->archive->run('vault.initialize');
    $first = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/items/pins',
        'asset_key' => 'master',
        'files' => ['data.bin' => $this->archive->input('old.bin', 'old')],
    ]);
    $item = $this->archive->run('item.record', [
        'uri' => 'https://example.test/items/pins',
        'pins' => [['object_id' => $first['object_id'], 'version' => $first['version']]],
    ]);
    $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/items/pins',
        'asset_key' => 'master',
        'files' => ['data.bin' => $this->archive->input('new.bin', 'new')],
    ]);

    $read = $this->archive->run('item.read', [
        'object_id' => $item['object_id'],
        'version' => $item['version'],
    ]);

    expect($read['pins'])->toBe([['object_id' => $first['object_id'], 'version' => $first['version']]]);
});

test('an Item revision cannot retroactively rewrite the pins on an old Item version', function (): void {
    $this->archive->run('vault.initialize');
    $asset1 = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/items/revisions',
        'asset_key' => 'master',
        'files' => ['data.bin' => $this->archive->input('rev1.bin', 'first')],
    ]);
    $item1 = $this->archive->run('item.record', [
        'uri' => 'https://example.test/items/revisions',
        'pins' => [['object_id' => $asset1['object_id'], 'version' => $asset1['version']]],
    ]);
    $asset2 = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/items/revisions',
        'asset_key' => 'master',
        'files' => ['data.bin' => $this->archive->input('rev2.bin', 'second')],
    ]);
    $item2 = $this->archive->run('item.record', [
        'uri' => 'https://example.test/items/revisions',
        'pins' => [['object_id' => $asset2['object_id'], 'version' => $asset2['version']]],
    ]);

    $first = $this->archive->run('item.read', ['object_id' => $item1['object_id'], 'version' => $item1['version']]);
    $second = $this->archive->run('item.read', ['object_id' => $item2['object_id'], 'version' => $item2['version']]);

    expect($item1['object_id'])->toBe($item2['object_id'])
        ->and($item1['version'])->not->toBe($item2['version'])
        ->and($first['pins'][0]['version'])->toBe($asset1['version'])
        ->and($second['pins'][0]['version'])->toBe($asset2['version']);
});

test('an Item cannot pin a nonexistent Asset version', function (): void {
    $this->archive->run('vault.initialize');

    expect(fn () => $this->archive->run('item.record', [
        'uri' => 'https://example.test/items/bad-pin',
        'pins' => [['object_id' => 'does-not-exist', 'version' => 'v999']],
    ]))->toThrow(ArchivesRejected::class);
});

test('an Item cannot claim another Item’s Asset as its own', function (): void {
    $this->archive->run('vault.initialize');
    $asset = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/items/owner-a',
        'asset_key' => 'master',
        'files' => ['a.bin' => $this->archive->input('a.bin', 'one')],
    ]);

    expect(fn () => $this->archive->run('item.record', [
        'uri' => 'https://example.test/items/owner-b',
        'pins' => [['object_id' => $asset['object_id'], 'version' => $asset['version']]],
    ]))->toThrow(ArchivesRejected::class);
});

test('non-HTTP canonical Item URIs can retain their archival identity', function (): void {
    $this->archive->run('vault.initialize');
    $item = $this->archive->run('item.record', [
        'uri' => 'urn:uuid:123e4567-e89b-12d3-a456-426614174000',
        'pins' => [],
    ]);

    $read = $this->archive->run('item.read', ['object_id' => $item['object_id']]);

    expect($read['uri'])->toBe('urn:uuid:123e4567-e89b-12d3-a456-426614174000');
});

test('metadata revisions remain readable without destroying their original observations', function (): void {
    $this->archive->run('vault.initialize');
    $first = $this->archive->run('item.record', [
        'uri' => 'https://example.test/items/titles',
        'pins' => [],
        'metadata' => ['title' => 'Original'],
    ]);
    $second = $this->archive->run('item.record', [
        'uri' => 'https://example.test/items/titles',
        'pins' => [],
        'metadata' => ['title' => 'Corrected'],
    ]);

    $old = $this->archive->run('item.read', ['object_id' => $first['object_id'], 'version' => $first['version']]);
    $new = $this->archive->run('item.read', ['object_id' => $second['object_id'], 'version' => $second['version']]);

    expect($first['object_id'])->toBe($second['object_id'])
        ->and($old['metadata']['title'])->toBe('Original')
        ->and($new['metadata']['title'])->toBe('Corrected');
});

test('rejected Item updates leave the last valid Item version intact', function (): void {
    $this->archive->run('vault.initialize');
    $item = $this->archive->run('item.record', [
        'uri' => 'https://example.test/items/safety',
        'pins' => [],
        'metadata' => ['title' => 'Keep me'],
    ]);

    expect(fn () => $this->archive->run('item.record', [
        'uri' => 'https://example.test/items/safety',
        'pins' => [['object_id' => 'missing', 'version' => 'v1']],
    ]))->toThrow(ArchivesRejected::class);

    $read = $this->archive->run('item.read', ['object_id' => $item['object_id']]);
    expect($read['metadata']['title'])->toBe('Keep me');
});
