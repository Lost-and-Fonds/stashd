<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Vault;

use App\Fixity\ChecksumComparisonOutcome;
use App\Fixity\VaultChecksum;
use RuntimeException;

test('vault checksum formats and verifies sha256 digests', function (): void {
    $path = sys_get_temp_dir() . '/stashd-checksum-' . bin2hex(random_bytes(4));
    file_put_contents($path, 'stashd-checksum-fixture');
    $computed = VaultChecksum::computeFile($path);

    expect($computed)->toBe('sha256:' . hash('sha256', 'stashd-checksum-fixture'))
        ->and(VaultChecksum::verifyFile($path, $computed)->outcome)->toBe(ChecksumComparisonOutcome::Match)
        ->and(VaultChecksum::verifyFile($path, 'sha256:' . str_repeat('0', 64))->outcome)->toBe(ChecksumComparisonOutcome::Mismatch)
        ->and(VaultChecksum::verifyFile($path, 'sha256:' . str_repeat('0', 64))->observedChecksum)->toBe($computed);

    unlink($path);
});

test('vault checksum reports an absent stored checksum as unverified', function (): void {
    $path = sys_get_temp_dir() . '/stashd-checksum-' . bin2hex(random_bytes(4));
    file_put_contents($path, 'no-checksum-yet');

    expect(VaultChecksum::verifyFile($path, null)->outcome)->toBe(ChecksumComparisonOutcome::Unverified)
        ->and(VaultChecksum::verifyFile($path, null)->observedChecksum)->toBe('sha256:' . hash('sha256', 'no-checksum-yet'));

    unlink($path);
});

test('vault checksum invokes its callback while streaming large files', function (): void {
    $path = sys_get_temp_dir() . '/stashd-checksum-' . bin2hex(random_bytes(4));
    file_put_contents($path, str_repeat('x', 2 * 1024 * 1024 + 1));
    $chunks = 0;

    $computed = VaultChecksum::computeFile($path, function () use (&$chunks): void {
        $chunks++;
    });

    expect($computed)->toBe('sha256:' . hash_file('sha256', $path))
        ->and($chunks)->toBe(3);

    unlink($path);
});

test('required checksum generation rejects an unavailable path', function (): void {
    expect(fn() => VaultChecksum::requiredFile(__DIR__))
        ->toThrow(RuntimeException::class, 'Unable to compute SHA-256 checksum');
});
