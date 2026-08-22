<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Storage;

use App\Config\StashdConfig;
use App\System\Storage\PathSanitizer;
use App\Vault\VaultPathBuilder;
use InvalidArgumentException;

test('path sanitizer rejects traversal segments', function (string $value): void {
    expect(fn() => PathSanitizer::sanitizeSegment($value))
        ->toThrow(InvalidArgumentException::class);
})->with([
    '../etc/passwd',
    '..\\windows\\system32',
    '/absolute/path',
    'C:\\Users\\secret',
    '%2e%2e%2fetc%2fpasswd',
    '',
    '   ',
]);

test('path sanitizer normalizes safe segments', function (): void {
    expect(PathSanitizer::sanitizeSegment('demo-episode-1'))->toBe('demo-episode-1')
        ->and(PathSanitizer::sanitizeSegment('original.fake'))->toBe('original.fake')
        ->and(PathSanitizer::sanitizeSegment('episode 🎬 one'))->toBe('episode-one');
});

test('path sanitizer truncates extremely long segments', function (): void {
    $long = str_repeat('a', 300);
    $sanitized = PathSanitizer::sanitizeSegment($long);

    expect(strlen($sanitized))->toBeLessThanOrEqual(200);
});

test('vault paths stay under vault root for sanitized provider ids', function (): void {
    $media = sys_get_temp_dir() . '/stashd-path-test/media';
    $config = new StashdConfig(
        dataPath: sys_get_temp_dir() . '/stashd-path-test/data',
        mediaPath: $media,
        publicUrl: 'http://localhost:8474',
        logFormat: 'text',
        puid: 1000,
        pgid: 1000,
        umask: '0022',
        httpPort: '8474',
    );
    $builder = new VaultPathBuilder($config);

    $path = $builder->vaultFile('fake', 'demo-episode-1', 'original.fake');

    expect($path)->toStartWith(rtrim($config->vaultPath(), '/'))
        ->and($path)->not->toContain('..');
});

test('vault path builder rejects traversal provider ids', function (): void {
    $config = new StashdConfig(
        dataPath: sys_get_temp_dir() . '/stashd-path-test/data',
        mediaPath: sys_get_temp_dir() . '/stashd-path-test/media',
        publicUrl: 'http://localhost:8474',
        logFormat: 'text',
        puid: 1000,
        pgid: 1000,
        umask: '0022',
        httpPort: '8474',
    );
    $builder = new VaultPathBuilder($config);

    expect(fn() => $builder->vaultFile('fake', '../../etc/passwd', 'original.fake'))
        ->toThrow(InvalidArgumentException::class);
});

test('vault path builder rejects segments that sanitize to empty', function (): void {
    $config = new StashdConfig(
        dataPath: sys_get_temp_dir() . '/stashd-path-test/data',
        mediaPath: sys_get_temp_dir() . '/stashd-path-test/media',
        publicUrl: 'http://localhost:8474',
        logFormat: 'text',
        puid: 1000,
        pgid: 1000,
        umask: '0022',
        httpPort: '8474',
    );
    $builder = new VaultPathBuilder($config);

    expect(fn() => $builder->vaultFile('fake', '!!!', 'original.fake'))
        ->toThrow(InvalidArgumentException::class);
});
