<?php

declare(strict_types=1);

use Tests\Support\RecordsAndArchives\OcflInspector;

test('an interrupted staged write cannot become a falsely committed Asset', function (): void {
    $this->archive->run('vault.initialize');

    $outcome = $this->archive->run('fault.interrupt', [
        'phase' => 'after_staging_before_publication',
        'operation' => 'asset.preserve',
        'arguments' => [
            'item_uri' => 'https://example.test/crashes/stage',
            'asset_key' => 'master',
            'files' => ['data.bin' => $this->archive->input('stage.bin', 'staged bytes')],
        ],
    ]);
    $this->archive->run('vault.open');
    $catalogue = $this->archive->run('catalogue.list');

    expect($outcome['reported_success'])->toBeFalse()
        ->and($catalogue['assets'])->toBe([]);
});

test('a crash after a durable Asset commit but before its Item commit leaves no invented Item pin', function (): void {
    $this->archive->run('vault.initialize');

    $outcome = $this->archive->run('fault.interrupt', [
        'phase' => 'after_asset_before_item',
        'operation' => 'item_and_asset.commit',
        'arguments' => [
            'uri' => 'https://example.test/crashes/split',
            'asset_key' => 'master',
            'files' => ['data.bin' => $this->archive->input('split.bin', 'committed Asset')],
        ],
    ]);

    $this->archive->run('vault.open');
    $catalogue = $this->archive->run('catalogue.list');

    expect($outcome['reported_success'])->toBeFalse()
        ->and($catalogue['items'])->toBe([]);

    // A valid but unlinked Asset is recoverable evidence, never a fabricated Item commit.
    expect($catalogue['assets'])->not->toBe([]);
});

test('an interrupted inventory publication reconciles instead of claiming success', function (): void {
    $this->archive->run('vault.initialize');

    $outcome = $this->archive->run('fault.interrupt', [
        'phase' => 'inventory_publication',
        'operation' => 'asset.preserve',
        'arguments' => [
            'item_uri' => 'https://example.test/crashes/inventory',
            'asset_key' => 'master',
            'files' => ['data.bin' => $this->archive->input('interrupt.bin', 'atomic evidence')],
        ],
    ]);

    $this->archive->run('vault.open');
    $verified = $this->archive->run('fixity.verify');

    expect($outcome['reported_success'])->toBeFalse()
        ->and($verified['healthy'])->toBeTrue();
});

test('two competing writers cannot corrupt or silently overwrite committed Asset history', function (): void {
    $this->archive->run('vault.initialize');

    $result = $this->archive->run('concurrency.preserve', [
        'item_uri' => 'https://example.test/concurrent/a',
        'asset_key' => 'master',
        'files' => [
            ['data.bin' => $this->archive->input('writer-a.bin', 'writer A')],
            ['data.bin' => $this->archive->input('writer-b.bin', 'writer B')],
        ],
    ]);

    expect($result['versions'])->not->toBe([]);

    foreach ($result['versions'] as $receipt) {
        $path = OcflInspector::payloadPath(
            $this->archive->path('vault'),
            $receipt['object_id'],
            $receipt['version'],
            'data.bin',
        );
        expect(file_get_contents($path))->toBeIn(['writer A', 'writer B']);
    }

    expect($this->archive->run('fixity.verify')['healthy'])->toBeTrue();
});

test('source mutation between digesting and publication cannot create a false fixity receipt', function (): void {
    $this->archive->run('vault.initialize');

    $outcome = $this->archive->run('fault.interrupt', [
        'phase' => 'mutate_source_during_adoption',
        'operation' => 'asset.preserve',
        'arguments' => [
            'item_uri' => 'https://example.test/concurrent/changed-source',
            'asset_key' => 'master',
            'files' => ['data.bin' => $this->archive->input('changed-source.bin', str_repeat('A', 4096))],
        ],
    ]);

    expect($outcome['reported_success'])->toBeFalse();
});

test('an interrupted operation cannot leave a complete Item that references missing bytes', function (): void {
    $this->archive->run('vault.initialize');

    $this->archive->run('fault.interrupt', [
        'phase' => 'during_item_publication',
        'operation' => 'item_and_asset.commit',
        'arguments' => [
            'uri' => 'https://example.test/crashes/dangling',
            'asset_key' => 'master',
            'files' => ['data.bin' => $this->archive->input('dangling.bin', 'must not dangle')],
        ],
    ]);

    $this->archive->run('vault.open');
    $catalogue = $this->archive->run('catalogue.list');

    foreach ($catalogue['items'] as $item) {
        $read = $this->archive->run('item.read', ['object_id' => $item['object_id']]);

        foreach ($read['pins'] as $pin) {
            $asset = OcflInspector::inventoryFor($this->archive->path('vault'), $pin['object_id']);
            expect(isset($asset['inventory']['versions'][$pin['version']]))->toBeTrue();
        }
    }

    expect($this->archive->run('fixity.verify')['healthy'])->toBeTrue();
});
