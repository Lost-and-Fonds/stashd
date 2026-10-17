<?php

declare(strict_types=1);

use Ottosmops\Ocfl\Validation\OcflException;
use Tests\Support\RecordsAndArchives\OcflInspector;

test('Vault writes file paths and inline content as an immutable OCFL history', function (): void {
    $this->archive->run('vault.initialize');
    $id = '123e4567-e89b-12d3-a456-426614174200';
    $first = $this->archive->run('vault.object_create', [
        'type' => 'asset',
        'uuid' => $id,
        'files' => [
            'master.bin' => $this->archive->input('master.bin', 'large media bytes'),
            'record.json' => ['contents' => '{"title":"first"}'],
        ],
    ]);
    $second = $this->archive->run('vault.object_update', [
        'object_id' => $first['object_id'],
        'files' => ['record.json' => ['contents' => '{"title":"second"}']],
    ]);

    expect($first['object_id'])
        ->toBe('urn:stashd:asset:' . $id)
        ->and($second['object_id'])
        ->toBe($first['object_id'])
        ->and($second['version'])
        ->not->toBe($first['version']);

    expect(
        $this->archive->run('vault.object_read', [
            'object_id' => $first['object_id'],
            'version' => $first['version'],
            'logical_path' => 'record.json',
        ])['bytes'],
    )
        ->toBe('{"title":"first"}');
    expect(
        $this->archive->run('vault.object_read', [
            'object_id' => $second['object_id'],
            'version' => $second['version'],
            'logical_path' => 'master.bin',
        ])['bytes'],
    )
        ->toBe('large media bytes');

    foreach ([$first, $second] as $receipt) {
        expect(file_get_contents(OcflInspector::payloadPath(
            $this->archive->path('vault'),
            $receipt['object_id'],
            $receipt['version'],
            'record.json',
        )))->not->toBeFalse();
    }
});

test('creating an existing typed UUID cannot overwrite its committed Object', function (): void {
    $this->archive->run('vault.initialize');
    $arguments = [
        'type' => 'asset',
        'uuid' => '123e4567-e89b-12d3-a456-426614174201',
        'files' => ['data.bin' => ['contents' => 'original']],
    ];
    $created = $this->archive->run('vault.object_create', $arguments);

    expect(fn () => $this->archive->run('vault.object_create', [
        ...$arguments,
        'files' => ['data.bin' => ['contents' => 'replacement']],
    ]))
        ->toThrow(OcflException::class);

    expect(
        $this->archive->run('vault.object_read', [
            'object_id' => $created['object_id'],
            'version' => $created['version'],
            'logical_path' => 'data.bin',
        ])['bytes'],
    )
        ->toBe('original');
});

test('a failed first write can retry an uncommitted Object UUID', function (): void {
    $this->archive->run('vault.initialize');
    $source = $this->archive->input('source.bin', 'source');
    $linkedSource = $this->archive->path('inputs/linked.bin');
    symlink($source, $linkedSource);
    $arguments = [
        'type' => 'asset',
        'uuid' => '123e4567-e89b-12d3-a456-426614174202',
        'files' => ['data.bin' => $linkedSource],
    ];

    expect(fn () => $this->archive->run('vault.object_create', $arguments))->toThrow(RuntimeException::class);

    $created = $this->archive->run('vault.object_create', [
        ...$arguments,
        'files' => ['data.bin' => ['contents' => 'retried']],
    ]);

    expect(
        $this->archive->run('vault.object_read', [
            'object_id' => $created['object_id'],
            'version' => $created['version'],
            'logical_path' => 'data.bin',
        ])['bytes'],
    )->toBe('retried');
});
