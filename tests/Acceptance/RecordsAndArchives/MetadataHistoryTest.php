<?php

declare(strict_types=1);

use Tests\Support\RecordsAndArchives\ArchivesRejected;

test('source-provided metadata remains recoverable as an immutable document revision', function (): void {
    $this->archive->run('vault.initialize');

    $record = $this->archive->run('metadata.record', [
        'item_uri' => 'https://example.test/metadata/a',
        'facet_key' => 'youtube.channel-details',
        'source' => 'input:youtube-1',
        'document' => ['title' => 'Old title', 'channel_id' => 'abc'],
    ]);
    $this->archive->run('vault.open');

    $read = $this->archive->run('metadata.read', [
        'object_id' => $record['object_id'],
        'version' => $record['version'],
    ]);

    expect($read['document'])->toBe(['title' => 'Old title', 'channel_id' => 'abc']);
});

test('updating one metadata stream retains the original version', function (): void {
    $this->archive->run('vault.initialize');
    $base = [
        'item_uri' => 'https://example.test/metadata/b',
        'facet_key' => 'source.description',
        'source' => 'input:site-1',
    ];

    $first = $this->archive->run('metadata.record', [...$base, 'document' => ['title' => 'Before']]);
    $second = $this->archive->run('metadata.record', [...$base, 'document' => ['title' => 'After']]);

    expect($second['object_id'])
        ->toBe($first['object_id'])
        ->and($second['version'])
        ->not
        ->toBe($first['version'])
        ->and(
            $this->archive->run('metadata.read', [
                'object_id' => $first['object_id'],
                'version' => $first['version'],
            ])['document']['title'],
        )
        ->toBe('Before')
        ->and(
            $this->archive->run('metadata.read', [
                'object_id' => $second['object_id'],
                'version' => $second['version'],
            ])['document']['title'],
        )
        ->toBe('After');
});

test('different sources for the same metadata facet keep separate provenance identities', function (): void {
    $this->archive->run('vault.initialize');
    $base = [
        'item_uri' => 'https://example.test/metadata/c',
        'facet_key' => 'source.details',
    ];

    $first = $this->archive->run('metadata.record', [
        ...$base,
        'source' => 'input:one',
        'document' => ['title' => 'One'],
    ]);
    $second = $this->archive->run('metadata.record', [
        ...$base,
        'source' => 'input:two',
        'document' => ['title' => 'Two'],
    ]);

    expect($first['object_id'])->not->toBe($second['object_id']);
});

test('editorial corrections cannot overwrite the original source observation', function (): void {
    $this->archive->run('vault.initialize');
    $uri = 'https://example.test/metadata/d';

    $source = $this->archive->run('metadata.record', [
        'item_uri' => $uri,
        'facet_key' => 'title',
        'source' => 'input:one',
        'document' => ['title' => 'Original source claim'],
    ]);
    $editorial = $this->archive->run('metadata.record', [
        'item_uri' => $uri,
        'facet_key' => 'title',
        'source' => 'editorial:user',
        'document' => ['title' => 'Human correction'],
    ]);

    expect($source['object_id'])
        ->not
        ->toBe($editorial['object_id'])
        ->and(
            $this->archive->run('metadata.read', [
                'object_id' => $source['object_id'],
                'version' => $source['version'],
            ])['document']['title'],
        )
        ->toBe('Original source claim');
});

test('invalid metadata must not replace a previously preserved valid revision', function (): void {
    $this->archive->run('vault.initialize');
    $base = [
        'item_uri' => 'https://example.test/metadata/e',
        'facet_key' => 'source.details',
        'source' => 'input:one',
    ];
    $good = $this->archive->run('metadata.record', [...$base, 'document' => ['title' => 'good']]);

    expect(fn () => $this->archive->run('metadata.record', [
        ...$base,
        'document' => 'not a JSON object',
    ]))
        ->toThrow(ArchivesRejected::class);

    expect(
        $this->archive->run('metadata.read', [
            'object_id' => $good['object_id'],
            'version' => $good['version'],
        ])['document'],
    )->toBe(['title' => 'good']);
});
