<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Vault;

use App\Vault\MoveFileIntoVault;

function moveFileIntoVaultScratchFile(string $contents = 'vault-bytes'): string
{
    $path = sys_get_temp_dir() . '/stashd-move-source-' . bin2hex(random_bytes(4));
    file_put_contents($path, $contents);

    return $path;
}

test('moveIntoPlace renames the file into place on the same device', function (): void {
    $source = moveFileIntoVaultScratchFile();
    $destinationDir = sys_get_temp_dir() . '/stashd-move-dest-' . bin2hex(random_bytes(4));
    $destination = $destinationDir . '/original.bin';

    (new MoveFileIntoVault())->moveIntoPlace($source, $destination);

    expect(file_exists($destination))->toBeTrue()
        ->and(file_get_contents($destination))->toBe('vault-bytes')
        ->and(file_exists($source))->toBeFalse();

    unlink($destination);
    rmdir($destinationDir);
});

test('moveIntoPlace creates a missing destination directory', function (): void {
    $source = moveFileIntoVaultScratchFile();
    $destinationDir = sys_get_temp_dir() . '/stashd-move-nested-' . bin2hex(random_bytes(4)) . '/a/b/c';
    $destination = $destinationDir . '/original.bin';

    (new MoveFileIntoVault())->moveIntoPlace($source, $destination);

    expect(is_dir($destinationDir))->toBeTrue()
        ->and(file_exists($destination))->toBeTrue();

    unlink($destination);
    rmdir($destinationDir);
});

test('moveIntoPlace refuses to overwrite an existing destination file', function (): void {
    $source = moveFileIntoVaultScratchFile('new-bytes');
    $destinationDir = sys_get_temp_dir() . '/stashd-move-existing-' . bin2hex(random_bytes(4));
    mkdir($destinationDir);
    $destination = $destinationDir . '/original.bin';
    file_put_contents($destination, 'already-here');

    expect(fn() => (new MoveFileIntoVault())->moveIntoPlace($source, $destination))
        ->toThrow(\RuntimeException::class);

    expect(file_get_contents($destination))->toBe('already-here');

    unlink($source);
    unlink($destination);
    rmdir($destinationDir);
});

test('moveIntoPlace falls back to copy when rename fails across devices', function (): void {
    $source = moveFileIntoVaultScratchFile();

    $crossDeviceRoot = '/dev/shm';

    if (! is_dir($crossDeviceRoot) || ! is_writable($crossDeviceRoot)) {
        unlink($source);
        $this->markTestSkipped('/dev/shm is not available as a separate writable filesystem in this environment.');
    }

    $sourceStat = stat($source);
    $crossDeviceStat = stat($crossDeviceRoot);

    if ($sourceStat === false || $crossDeviceStat === false || $sourceStat['dev'] === $crossDeviceStat['dev']) {
        unlink($source);
        $this->markTestSkipped('Temp dir and /dev/shm share a device here; cannot force a real cross-device rename failure.');
    }

    $destinationDir = $crossDeviceRoot . '/stashd-move-test-' . bin2hex(random_bytes(4));
    $destination = $destinationDir . '/original.bin';

    (new MoveFileIntoVault())->moveIntoPlace($source, $destination);

    expect(file_exists($destination))->toBeTrue()
        ->and(file_get_contents($destination))->toBe('vault-bytes')
        ->and(file_exists($source))->toBeFalse();

    unlink($destination);
    rmdir($destinationDir);
});
