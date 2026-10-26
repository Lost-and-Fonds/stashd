<?php

use App\Integration\BroadcastStream;
use App\Integration\DTO\BroadcastSelection;
use App\Integration\DTO\PublishedFile;

test('publication paths reject unsafe names', function (string $path): void {
    expect(fn () => new PublishedFile($path))->toThrow(InvalidArgumentException::class);
})->with(['', '/', '../outside', 'a/../b', 'a//b', './a', 'a/', 'C:drive', 'a\\b', "a\0b"]);

test('publication paths retain supported Unicode and spaces', function (): void {
    expect(new PublishedFile('nested/é space.bin')->relativePath)->toBe('nested/é space.bin');
});

test('selection requires explicit Item identity and version', function (string $id, string $version): void {
    expect(fn () => new BroadcastSelection($id, $version))->toThrow(InvalidArgumentException::class);
})->with([
    ['urn:stashd:asset:123', 'v1'],
    ['urn:stashd:item:123',  'latest'],
    ['urn:stashd:item:123',  'v0'],
    ['urn:stashd:item:123',  'v01'],
]);

test('bounded streams detect truncation reject invalid chunks and revoke reads', function (): void {
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('Could not open proof stream.');
    }

    fwrite($stream, 'abc');
    rewind($stream);
    $active = true;
    $reader = new BroadcastStream($stream, 4, static function () use (&$active): void {
        if (! $active) {
            throw new RuntimeException('Invocation ended.');
        }
    });
    expect(fn () => $reader->read(0))->toThrow(InvalidArgumentException::class);
    expect($reader->read())->toBe('abc');
    expect($reader->read(...))->toThrow(RuntimeException::class, 'before its verified extent');
    $active = false;
    expect($reader->read(...))->toThrow(RuntimeException::class, 'Invocation ended');
    $reader->close();
    expect(is_resource($stream))->toBeFalse();
});
