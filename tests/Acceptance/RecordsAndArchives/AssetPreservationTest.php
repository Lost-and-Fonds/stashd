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
    OcflInspector::assertInventorySidecar(dirname($found['path']) . '/v1/inventory.json');

    $physical = OcflInspector::payloadPath(
        $this->archive->path('vault'),
        $receipt['object_id'],
        $receipt['version'],
        'master.bin',
    );

    expect(file_get_contents($physical))
        ->toBe($bytes)
        ->and(
            $this->archive->run('asset.read', [
                'object_id' => $receipt['object_id'],
                'version' => $receipt['version'],
                'logical_path' => 'master.bin',
            ])['bytes'],
        )
        ->toBe($bytes);
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

    expect($second['object_id'])
        ->toBe($first['object_id'])
        ->and($second['version'])
        ->not->toBe($first['version']);

    foreach ([
        [$first,  'old bytes'],
        [$second, 'new bytes'],
    ] as [$receipt, $expected]) {
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
        )))
            ->toBe($bytes);
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
    ]))
        ->toThrow(ArchivesRejected::class);

    expect(file_exists($this->archive->path('outside.bin')))->toBeFalse();
});

test('a missing acquisition source cannot yield a successful preservation receipt', function (): void {
    $this->archive->run('vault.initialize');

    expect(fn () => $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/records/7',
        'asset_key' => 'master',
        'files' => ['master.bin' => $this->archive->path('missing.bin')],
    ]))
        ->toThrow(ArchivesRejected::class);
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

test('accepted byte evidence independently matches physical OCFL inventory and payload', function (): void {
    $this->archive->run('vault.initialize');
    $bytes = random_bytes(65_537);
    $source = $this->archive->input('evidence.bin', $bytes);
    $receipt = $this->archive->run('asset.preserve', ['item_uri' => 'urn:test:evidence', 'asset_key' => 'master', 'files' => ['payload.bin' => $source]]);
    $found = OcflInspector::inventoryFor($this->archive->path('vault'), $receipt['object_id']);
    $digest = hash('sha512', $bytes);
    expect($receipt['files']['payload.bin'])
        ->toBe(['size' => strlen($bytes), 'algorithm' => 'sha512', 'digest' => $digest])
        ->and($found['inventory']['versions']['v1']['state'][$digest])
        ->toContain('payload.bin');
    $physical = OcflInspector::payloadPath($this->archive->path('vault'), $receipt['object_id'], 'v1', 'payload.bin');
    expect(hash_file('sha512', $physical))->toBe($digest);
    unlink($source);
    expect($this->archive->run('asset.read', ['object_id' => $receipt['object_id'], 'version' => 'v1', 'logical_path' => 'payload.bin'])['bytes'])->toBe($bytes);
});

test('rejected revisions leave committed history unchanged', function (string $kind): void {
    $this->archive->run('vault.initialize');
    $source = $this->archive->input('safe.bin', 'safe bytes');
    $args = ['item_uri' => 'urn:test:reject', 'asset_key' => 'master', 'files' => ['safe.bin' => $source]];
    $receipt = $this->archive->run('asset.preserve', $args);
    $found = OcflInspector::inventoryFor($this->archive->path('vault'), $receipt['object_id']);
    $before = file_get_contents($found['path']);
    $files = match ($kind) {
        'traversal' => ['../escape' => $source],
        'missing' => ['missing.bin' => $this->archive->path('missing')],
        'reserved' => ['.stashd/asset.json' => $source],
        'prefix' => ['a' => $source, 'a/b' => $source],
        'empty' => [],
        default => ['linked.bin' => $this->archive->path('linked')],
    };
    if ($kind === 'symlink') {
        symlink($source, $this->archive->path('linked'));
    } elseif ($kind === 'ancestor') {
        symlink(dirname($source), $this->archive->path('linked'));
        $files = ['linked.bin' => $this->archive->path('linked/safe.bin')];
    }
    expect(fn () => $this->archive->run('asset.preserve', [...$args, 'files' => $files]))->toThrow(ArchivesRejected::class);
    expect(file_get_contents($found['path']))
        ->toBe($before)
        ->and($this->archive->run('asset.read', ['object_id' => $receipt['object_id'], 'version' => 'v1', 'logical_path' => 'safe.bin'])['bytes'])
        ->toBe('safe bytes');
})->with(['traversal', 'missing', 'reserved', 'prefix', 'empty', 'symlink', 'ancestor']);

test('multi-file revisions replace the complete file set and retain historical files', function (): void {
    $this->archive->run('vault.initialize');
    $a = $this->archive->input('a', 'A');
    $b = $this->archive->input('b', 'B');
    $args = ['item_uri' => 'urn:test:multifile-history', 'asset_key' => 'master'];
    $first = $this->archive->run('asset.preserve', [...$args, 'files' => ['a' => $a, 'b' => $b]]);
    $second = $this->archive->run('asset.preserve', [...$args, 'files' => ['renamed' => $a]]);
    expect($second['version'])->toBe('v2')->and(array_keys($second['files']))->toBe(['renamed']);
    foreach (['a' => 'A', 'b' => 'B'] as $path => $bytes) {
        expect($this->archive->run('asset.read', ['object_id' => $first['object_id'], 'version' => 'v1', 'logical_path' => $path])['bytes'])->toBe($bytes);
    }
    expect(fn () => $this->archive->run('asset.read', ['object_id' => $first['object_id'], 'version' => 'v2', 'logical_path' => 'b']))
        ->toThrow(\App\RecordsAndArchives\InvalidAsset::class);
});

test('missing objects versions and paths are honest read failures', function (): void {
    $this->archive->run('vault.initialize');
    $receipt = $this->archive->run('asset.preserve', ['item_uri' => 'urn:test:missing', 'asset_key' => 'master', 'files' => ['a' => $this->archive->input('a', 'A')]]);
    foreach ([
        ['object_id' => 'urn:stashd:asset:00000000-0000-4000-8000-000000000000'],
        ['version' => 'v999'],
        ['logical_path' => 'absent'],
        ['logical_path' => '../a'],
    ] as $override) {
        expect(fn () => $this->archive->run('asset.read', ['object_id' => $receipt['object_id'], 'version' => 'v1', 'logical_path' => 'a', ...$override]))
            ->toThrow(\Exception::class);
    }
});

test('corruption is detected by real reads and blocks further revisions', function (): void {
    $this->archive->run('vault.initialize');
    $args = ['item_uri' => 'urn:test:corruption', 'asset_key' => 'master', 'files' => ['a' => $this->archive->input('a', 'A')]];
    $receipt = $this->archive->run('asset.preserve', $args);
    $found = OcflInspector::inventoryFor($this->archive->path('vault'), $receipt['object_id']);
    $before = file_get_contents($found['path']);
    file_put_contents(OcflInspector::payloadPath($this->archive->path('vault'), $receipt['object_id'], 'v1', 'a'), 'X');
    expect(fn () => $this->archive->run('asset.read', ['object_id' => $receipt['object_id'], 'version' => 'v1', 'logical_path' => 'a']))->toThrow(\UnexpectedValueException::class);
    expect(fn () => $this->archive->run('asset.preserve', $args))->toThrow(\UnexpectedValueException::class);
    expect(file_get_contents($found['path']))->toBe($before);
});

test('an unrelated OCFL Object cannot be revised as an Asset', function (): void {
    $this->archive->run('vault.initialize');
    $container = new \Tempest\Container\GenericContainer();
    $container->config(new \Tempest\Storage\Config\LocalStorageConfig(path: $this->archive->path('vault'), tag: 'vault'));
    $container->addInitializer(\Tempest\Storage\StorageInitializer::class);
    $container->addInitializer(\App\RecordsAndArchives\Storage\ArchiveStorageInitializer::class);
    $id = $container->get(\App\RecordsAndArchives\AssetArchive::class)->objectId('urn:test:collision', 'master');
    $this->archive->run('vault.object_create', [
        'type' => 'asset',
        'uuid' => substr($id->toString(), strlen('urn:stashd:asset:')),
        'files' => ['unrelated' => ['contents' => 'unrelated']],
    ]);
    $before = OcflInspector::inventoryFor($this->archive->path('vault'), $id->toString());
    expect(fn () => $this->archive->run('asset.preserve', ['item_uri' => 'urn:test:collision', 'asset_key' => 'master', 'files' => ['a' => $this->archive->input('a', 'A')]]))
        ->toThrow(\Exception::class);
    expect(OcflInspector::inventoryFor($this->archive->path('vault'), $id->toString())['inventory'])->toBe($before['inventory']);
});

test('adopted-byte corruption is rejected before OCFL publishes a revision', function (): void {
    $this->archive->run('vault.initialize');
    $args = ['item_uri' => 'urn:test:copy-fault', 'asset_key' => 'master', 'files' => ['a' => $this->archive->input('first', 'first')]];
    $receipt = $this->archive->run('asset.preserve', $args);
    $found = OcflInspector::inventoryFor($this->archive->path('vault'), $receipt['object_id']);
    $before = file_get_contents($found['path']);
    $config = new \Tempest\Storage\Config\LocalStorageConfig(path: $this->archive->path('vault'), tag: 'vault');
    $container = new \Tempest\Container\GenericContainer();
    $container->singleton(
        \App\RecordsAndArchives\Storage\ArchiveStorage::class,
        new \Tests\Support\RecordsAndArchives\CorruptingAssetStorage(new \Tempest\Storage\GenericStorage($config), $config),
        'vault',
    );
    $assets = $container->get(\App\RecordsAndArchives\AssetArchive::class);
    expect(fn () => $assets->preserve($args['item_uri'], $args['asset_key'], ['a' => $this->archive->input(
        'second',
        'second',
    )]))->toThrow(RuntimeException::class, 'Adopted bytes differ');
    expect(file_get_contents($found['path']))
        ->toBe($before)
        ->and(is_dir(dirname($found['path']) . '/v2'))
        ->toBeFalse()
        ->and($this->archive->run('asset.read', ['object_id' => $receipt['object_id'], 'version' => 'v1', 'logical_path' => 'a'])['bytes'])
        ->toBe('first');
});
