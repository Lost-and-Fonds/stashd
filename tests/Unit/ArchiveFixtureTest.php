<?php

declare(strict_types=1);

use Tests\Support\RecordsAndArchives\ArchiveFixture;

test('archive acceptance workspaces are unique and contain their own inputs', function (): void {
    $first = new ArchiveFixture();
    $second = new ArchiveFixture();

    try {
        $file = $first->input('sample.bin', "real bytes\0");
        expect(file_get_contents($file))
            ->toBe("real bytes\0")
            ->and($first->path('vault'))
            ->not
            ->toBe($second->path('vault'))
            ->and(file_exists($second->path('inputs/sample.bin')))
            ->toBeFalse();
    } finally {
        $first->dispose();
        $second->dispose();
    }

    expect(file_exists($file))->toBeFalse();
});

test('archive fixture path validation blocks traversal outside disposable roots', function (): void {
    $fixture = new ArchiveFixture();

    try {
        expect(fn () => $fixture->path('../danger'))->toThrow(InvalidArgumentException::class);
        expect(fn () => $fixture->path('/etc/passwd'))->toThrow(InvalidArgumentException::class);
    } finally {
        $fixture->dispose();
    }
});
