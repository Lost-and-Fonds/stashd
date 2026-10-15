<?php

declare(strict_types=1);

test('an open expected-component set does not justify claiming complete preservation', function (): void {
    $this->archive->run('vault.initialize');
    $asset = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/scope/open',
        'asset_key' => 'master',
        'files' => ['a.bin' => $this->archive->input('open.bin', 'known bytes')],
        'scope' => ['state' => 'open', 'expected' => ['a.bin']],
    ]);

    $evidence = $this->archive->run('asset.describe', [
        'object_id' => $asset['object_id'],
        'version' => $asset['version'],
    ]);

    expect($evidence['complete'])->toBeFalse();
});

test('a closed known scope with verified components may be reported complete', function (): void {
    $this->archive->run('vault.initialize');
    $asset = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/scope/closed',
        'asset_key' => 'master',
        'files' => [
            'a.bin' => $this->archive->input('closed-a.bin', 'A'),
            'b.bin' => $this->archive->input('closed-b.bin', 'B'),
        ],
        'scope' => ['state' => 'closed', 'expected' => ['a.bin', 'b.bin']],
    ]);

    $evidence = $this->archive->run('asset.describe', [
        'object_id' => $asset['object_id'],
        'version' => $asset['version'],
    ]);

    expect($evidence['complete'])->toBeTrue()
        ->and($evidence['known_missing'])->toBe([]);
});

test('a partial Asset with missing known components remains preserved but incomplete', function (): void {
    $this->archive->run('vault.initialize');
    $asset = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/scope/partial',
        'asset_key' => 'master',
        'files' => ['a.bin' => $this->archive->input('partial.bin', 'valid partial evidence')],
        'scope' => ['state' => 'closed', 'expected' => ['a.bin', 'b.bin']],
    ]);

    $evidence = $this->archive->run('asset.describe', [
        'object_id' => $asset['object_id'],
        'version' => $asset['version'],
    ]);

    expect($evidence['complete'])->toBeFalse()
        ->and($evidence['known_missing'])->toContain('b.bin')
        ->and($this->archive->run('asset.read', [
            'object_id' => $asset['object_id'],
            'version' => $asset['version'],
            'logical_path' => 'a.bin',
        ])['bytes'])->toBe('valid partial evidence');
});

test('later complete acquisition cannot retroactively declare an older partial version complete', function (): void {
    $this->archive->run('vault.initialize');
    $uri = 'https://example.test/scope/history';
    $old = $this->archive->run('asset.preserve', [
        'item_uri' => $uri,
        'asset_key' => 'master',
        'files' => ['a.bin' => $this->archive->input('partial-history.bin', 'A')],
        'scope' => ['state' => 'closed', 'expected' => ['a.bin', 'b.bin']],
    ]);
    $new = $this->archive->run('asset.preserve', [
        'item_uri' => $uri,
        'asset_key' => 'master',
        'files' => [
            'a.bin' => $this->archive->input('complete-a.bin', 'A'),
            'b.bin' => $this->archive->input('complete-b.bin', 'B'),
        ],
        'scope' => ['state' => 'closed', 'expected' => ['a.bin', 'b.bin']],
    ]);

    $earlier = $this->archive->run('asset.describe', [
        'object_id' => $old['object_id'],
        'version' => $old['version'],
    ]);
    $later = $this->archive->run('asset.describe', [
        'object_id' => $new['object_id'],
        'version' => $new['version'],
    ]);

    expect($earlier['complete'])->toBeFalse()
        ->and($later['complete'])->toBeTrue();
});

test('reacquiring identical source bytes still preserves new provenance evidence', function (): void {
    $this->archive->run('vault.initialize');
    $input = $this->archive->input('same-content.bin', 'same versioned content');

    $first = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/scope/provenance',
        'asset_key' => 'master',
        'files' => ['data.bin' => $input],
        'provenance' => ['attempt_id' => 'attempt-one'],
    ]);
    $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/scope/provenance',
        'asset_key' => 'master',
        'files' => ['data.bin' => $input],
        'provenance' => ['attempt_id' => 'attempt-two'],
    ]);

    $events = $this->archive->run('history.events', ['object_id' => $first['object_id']]);
    $evidence = json_encode($events['events'], JSON_THROW_ON_ERROR);

    expect($evidence)->toContain('attempt-one')
        ->and($evidence)->toContain('attempt-two');
});
