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
    )
        ->toBe('retried');
});

test('stream reads resolve the requested historical version', function (): void {
    $this->archive->run('vault.initialize');
    $created = $this->archive->run('vault.object_create', [
        'type' => 'asset',
        'uuid' => '123e4567-e89b-12d3-a456-426614174203',
        'files' => ['data.bin' => ['contents' => 'version one']],
    ]);
    $updated = $this->archive->run('vault.object_update', [
        'object_id' => $created['object_id'],
        'files' => ['data.bin' => ['contents' => 'version two']],
    ]);

    foreach ([
        [$created, 'version one'],
        [$updated, 'version two'],
    ] as [$receipt, $expected]) {
        $stream = $this->archive->run('vault.object_stream_read', [
            'object_id' => $created['object_id'],
            'version' => $receipt['version'],
            'logical_path' => 'data.bin',
        ])['stream'];

        try {
            expect(stream_get_contents($stream))->toBe($expected);
        } finally {
            fclose($stream);
        }
    }
});

test('stream reads reject missing Objects, versions, and logical paths', function (): void {
    $this->archive->run('vault.initialize');
    $created = $this->archive->run('vault.object_create', [
        'type' => 'asset',
        'uuid' => '123e4567-e89b-12d3-a456-426614174204',
        'files' => ['data.bin' => ['contents' => 'present']],
    ]);

    expect(fn () => $this->archive->run('vault.object_stream_read', [
        'object_id' => 'urn:stashd:asset:123e4567-e89b-12d3-a456-426614174205',
        'version' => 'v1',
        'logical_path' => 'data.bin',
    ]))
        ->toThrow(Ottosmops\Ocfl\Validation\OcflException::class);

    expect(fn () => $this->archive->run('vault.object_stream_read', [
        'object_id' => $created['object_id'],
        'version' => 'v9',
        'logical_path' => 'data.bin',
    ]))
        ->toThrow(OutOfBoundsException::class);

    expect(fn () => $this->archive->run('vault.object_stream_read', [
        'object_id' => $created['object_id'],
        'version' => 'v1',
        'logical_path' => 'missing.bin',
    ]))
        ->toThrow(OutOfBoundsException::class);
});

test('large historical content streams with bounded PHP memory', function (): void {
    $this->archive->run('vault.initialize');
    $path = $this->archive->path('inputs/large.bin');
    mkdir(dirname($path), 0o700, true);
    $output = fopen($path, 'wb');
    $chunk = str_repeat('0123456789abcdef', 4096);
    $expectedHash = hash_init('sha256');
    $bytes = 32 * 1024 * 1024;

    try {
        for ($written = 0; $written < $bytes; $written += strlen($chunk)) {
            if (fwrite($output, $chunk) !== strlen($chunk)) {
                throw new RuntimeException('Failed while creating large test content.');
            }

            hash_update($expectedHash, $chunk);
        }
    } finally {
        fclose($output);
    }

    $writeBaseline = memory_get_usage(false);
    memory_reset_peak_usage();
    $created = $this->archive->run('vault.object_create', [
        'type' => 'asset',
        'uuid' => '123e4567-e89b-12d3-a456-426614174206',
        'files' => ['large.bin' => $path],
    ]);
    expect(memory_get_peak_usage(false) - $writeBaseline)->toBeLessThan(2 * 1024 * 1024);

    $baseline = memory_get_usage(false);
    memory_reset_peak_usage();
    $stream = $this->archive->run('vault.object_stream_read', [
        'object_id' => $created['object_id'],
        'version' => $created['version'],
        'logical_path' => 'large.bin',
    ])['stream'];
    $actualHash = hash_init('sha256');
    $read = 0;

    try {
        while (! feof($stream)) {
            $buffer = fread($stream, 64 * 1024);

            if ($buffer === false) {
                throw new RuntimeException('Failed while reading archived test content.');
            }

            $read += strlen($buffer);
            hash_update($actualHash, $buffer);
        }
    } finally {
        fclose($stream);
    }

    expect($read)
        ->toBe($bytes)
        ->and(hash_final($actualHash))
        ->toBe(hash_final($expectedHash))
        ->and(memory_get_peak_usage(false) - $baseline)
        ->toBeLessThan(2 * 1024 * 1024);
});
