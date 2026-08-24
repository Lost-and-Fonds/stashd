<?php

declare(strict_types=1);

test('released migrations retain their frozen source', function (): void {
    $manifest = json_decode(
        file_get_contents(__DIR__ . '/../../fixtures/migrations/frozen.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    foreach ($manifest as $path => $hash) {
        expect(hash_file('sha256', dirname(__DIR__, 3) . '/' . $path))->toBe($hash);
    }
});
