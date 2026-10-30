<?php

declare(strict_types=1);

use App\Operations\Work;

test('Work rejects identifiers that cannot be canonical database keys', function (string $identifier): void {
    expect(fn () => new Work('discovery', $identifier, 'refresh', 'revision-1'))->toThrow(InvalidArgumentException::class);
})->with(['', ' ambiguous ', "null\0byte", str_repeat('x', 201), '非ASCII']);

test('Work rejects an empty or null-containing observation', function (string $observation): void {
    expect(fn () => new Work('discovery', 'scope', 'refresh', $observation))->toThrow(InvalidArgumentException::class);
})->with(['', "null\0byte", str_repeat('x', 1025)]);
