<?php

declare(strict_types=1);

use Tests\Support\RecordsAndArchives\OcflInspector;

beforeEach(function (): void {
    $this->markTestIncomplete('Replica synchronization and recovery, including Item pins, are not implemented.');
});

test('a replica of a complete Vault has the same identity and a verified coherent recovery point', function (): void {
    $initial = $this->archive->run('vault.initialize');
    $asset = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/replica/one',
        'asset_key' => 'master',
        'files' => ['data.bin' => $this->archive->input('replica.bin', 'primary content')],
    ]);
    $this->archive->run('item.record', [
        'uri' => 'https://example.test/replica/one',
        'pins' => [['object_id' => $asset['object_id'], 'version' => $asset['version']]],
    ]);

    $replica = $this->archive->path('replica');
    $synced = $this->archive->run('replica.sync', ['replica' => $replica]);
    $verified = $this->archive->run('replica.verify', ['replica' => $replica]);

    OcflInspector::assertRoot($replica);

    expect($synced['complete'])
        ->toBeTrue()
        ->and($synced['recovery_point'])
        ->toBeString()
        ->not->toBeEmpty()->and($verified['healthy'])->toBeTrue()->and($verified['recovery_point'])->toBe($synced['recovery_point'])->and($initial['vault_id'])->toBeString()
        ->not->toBeEmpty();
});

test('clean-room recovery retains the original Vault identity without the old primary or database', function (): void {
    $initial = $this->archive->run('vault.initialize');
    $asset = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/replica/resurrection',
        'asset_key' => 'master',
        'files' => ['data.bin' => $this->archive->input('resurrect.bin', 'recoverable')],
    ]);
    $this->archive->run('item.record', [
        'uri' => 'https://example.test/replica/resurrection',
        'pins' => [['object_id' => $asset['object_id'], 'version' => $asset['version']]],
    ]);
    $replica = $this->archive->path('replica');
    $this->archive->run('replica.sync', ['replica' => $replica]);

    // Remove the actual canonical storage root before invoking recovery.
    // The adapter must additionally use an empty database, fresh application
    // context and no cached source-plugin data.
    $this->archive->destroy('vault');
    expect(file_exists($this->archive->path('vault')))->toBeFalse();

    $destination = $this->archive->path('restored-vault');
    $recovered = $this->archive->run('replica.recover', [
        'replica' => $replica,
        'destination' => $destination,
        'old_primary_unavailable' => true,
        'empty_database' => true,
    ]);

    expect($recovered['complete'])->toBeTrue()->and($recovered['vault_id'])->toBe($initial['vault_id']);

    OcflInspector::assertRoot($destination);
    $read = $this->archive->run('asset.read', [
        'primary' => $destination,
        'object_id' => $asset['object_id'],
        'version' => $asset['version'],
        'logical_path' => 'data.bin',
    ]);

    expect($read['bytes'])->toBe('recoverable');
});

test('a recovered Item still pins the old exact Asset revision after later revisions existed', function (): void {
    $this->archive->run('vault.initialize');
    $uri = 'https://example.test/replica/historical';

    $old = $this->archive->run('asset.preserve', [
        'item_uri' => $uri,
        'asset_key' => 'master',
        'files' => ['data.bin' => $this->archive->input('old.bin', 'old content')],
    ]);
    $item = $this->archive->run('item.record', [
        'uri' => $uri,
        'pins' => [['object_id' => $old['object_id'], 'version' => $old['version']]],
    ]);
    $new = $this->archive->run('asset.preserve', [
        'item_uri' => $uri,
        'asset_key' => 'master',
        'files' => ['data.bin' => $this->archive->input('new.bin', 'new content')],
    ]);
    $replica = $this->archive->path('replica');
    $this->archive->run('replica.sync', ['replica' => $replica]);
    $destination = $this->archive->path('restored');

    $this->archive->run('replica.recover', [
        'replica' => $replica,
        'destination' => $destination,
        'old_primary_unavailable' => true,
        'empty_database' => true,
    ]);

    $recoveredItem = $this->archive->run('item.read', [
        'primary' => $destination,
        'object_id' => $item['object_id'],
        'version' => $item['version'],
    ]);

    expect($recoveredItem['pins'])
        ->toBe([['object_id' => $old['object_id'], 'version' => $old['version']]])
        ->and(file_get_contents(OcflInspector::payloadPath(
            $destination,
            $new['object_id'],
            $new['version'],
            'data.bin',
        )))
        ->toBe('new content');
});

test('rebuilding the catalogue from an independently verified replica retains metadata-only Items', function (): void {
    $this->archive->run('vault.initialize');
    $this->archive->run('item.record', [
        'uri' => 'urn:uuid:123e4567-e89b-12d3-a456-426614174001',
        'pins' => [],
        'metadata' => ['title' => 'Found but never acquired'],
    ]);
    $replica = $this->archive->path('replica');
    $this->archive->run('replica.sync', ['replica' => $replica]);
    $destination = $this->archive->path('restored');

    $this->archive->run('replica.recover', [
        'replica' => $replica,
        'destination' => $destination,
        'old_primary_unavailable' => true,
        'empty_database' => true,
    ]);

    $catalogue = $this->archive->run('catalogue.list', ['primary' => $destination]);
    $uris = array_column($catalogue['items'], 'uri');

    expect($uris)->toContain('urn:uuid:123e4567-e89b-12d3-a456-426614174001');
});

test('a lagging replica cannot claim to contain changes newer than its own verified recovery point', function (): void {
    $this->archive->run('vault.initialize');
    $this->archive->run('item.record', ['uri' => 'https://example.test/replica/earlier', 'pins' => []]);
    $replica = $this->archive->path('replica');
    $first = $this->archive->run('replica.sync', ['replica' => $replica]);
    $this->archive->run('item.record', ['uri' => 'https://example.test/replica/later', 'pins' => []]);

    $destination = $this->archive->path('restored');
    $recovered = $this->archive->run('replica.recover', [
        'replica' => $replica,
        'destination' => $destination,
        'old_primary_unavailable' => true,
        'empty_database' => true,
    ]);
    $catalogue = $this->archive->run('catalogue.list', ['primary' => $destination]);
    $uris = array_column($catalogue['items'], 'uri');

    expect($recovered['recovery_point'])
        ->toBe($first['recovery_point'])
        ->and($uris)
        ->toContain('https://example.test/replica/earlier')
        ->and($uris)
        ->not->toContain('https://example.test/replica/later');
});

test('a corrupted replica cannot be declared a complete recoverable Vault', function (): void {
    $this->archive->run('vault.initialize');
    $asset = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/replica/corrupt',
        'asset_key' => 'master',
        'files' => ['data.bin' => $this->archive->input('replica-corrupt.bin', 'good bytes')],
    ]);
    $this->archive->run('item.record', [
        'uri' => 'https://example.test/replica/corrupt',
        'pins' => [['object_id' => $asset['object_id'], 'version' => $asset['version']]],
    ]);

    $replica = $this->archive->path('replica');
    $this->archive->run('replica.sync', ['replica' => $replica]);
    file_put_contents(
        OcflInspector::payloadPath($replica, $asset['object_id'], $asset['version'], 'data.bin'),
        'wrong bytes',
    );

    $result = $this->archive->run('replica.verify', ['replica' => $replica]);

    expect($result['healthy'])->toBeFalse();
});

test('a restore never writes archival repairs or new authority into its source replica', function (): void {
    $this->archive->run('vault.initialize');
    $asset = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/replica/read-only',
        'asset_key' => 'master',
        'files' => ['data.bin' => $this->archive->input('readonly.bin', 'replicated bytes')],
    ]);
    $this->archive->run('item.record', [
        'uri' => 'https://example.test/replica/read-only',
        'pins' => [['object_id' => $asset['object_id'], 'version' => $asset['version']]],
    ]);
    $replica = $this->archive->path('replica');
    $this->archive->run('replica.sync', ['replica' => $replica]);

    $before = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($replica, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $before[substr($file->getPathname(), strlen($replica) + 1)] = hash_file('sha256', $file->getPathname());
    }
    ksort($before);

    $this->archive->run('replica.recover', [
        'replica' => $replica,
        'destination' => $this->archive->path('restored'),
        'old_primary_unavailable' => true,
        'empty_database' => true,
    ]);

    $after = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($replica, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $after[substr($file->getPathname(), strlen($replica) + 1)] = hash_file('sha256', $file->getPathname());
    }
    ksort($after);

    expect($after)->toBe($before);
});

test('a complete restore preserves archival event evidence rather than rebuilding only current media', function (): void {
    $this->archive->run('vault.initialize');
    $uri = 'https://example.test/replica/history';
    $first = $this->archive->run('asset.preserve', [
        'item_uri' => $uri,
        'asset_key' => 'master',
        'files' => ['data.bin' => $this->archive->input('history-a.bin', 'a')],
    ]);
    $this->archive->run('asset.preserve', [
        'item_uri' => $uri,
        'asset_key' => 'master',
        'files' => ['data.bin' => $this->archive->input('history-b.bin', 'b')],
    ]);
    $original = $this->archive->run('history.events', ['object_id' => $first['object_id']]);
    $replica = $this->archive->path('replica');
    $this->archive->run('replica.sync', ['replica' => $replica]);
    $destination = $this->archive->path('restored');

    $this->archive->run('replica.recover', [
        'replica' => $replica,
        'destination' => $destination,
        'old_primary_unavailable' => true,
        'empty_database' => true,
    ]);

    $recovered = $this->archive->run('history.events', [
        'primary' => $destination,
        'object_id' => $first['object_id'],
    ]);

    expect($recovered['events'])->toBe($original['events']);
});
