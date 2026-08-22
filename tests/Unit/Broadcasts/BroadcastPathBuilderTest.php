<?php

declare(strict_types=1);

namespace Tests\Unit\Broadcasts;

use App\Broadcasts\BroadcastException;
use App\Broadcasts\BroadcastPathBuilder;
use App\Broadcasts\BroadcastRecord;
use App\Broadcasts\BroadcastState;
use App\Config\StashdConfig;
use App\Stashes\StashId;
use InvalidArgumentException;
use Tempest\Database\PrimaryKey;

function pathBuilderTestConfig(string $root): StashdConfig
{
    return new StashdConfig(
        dataPath: $root . '/data',
        mediaPath: $root . '/media',
        publicUrl: 'http://localhost:8474',
        logFormat: 'text',
        puid: 1000,
        pgid: 1000,
        umask: '0022',
        httpPort: '8474',
    );
}

function pathBuilderTestBroadcast(string $type = 'jellyfin', string $name = 'Breaking Bad', ?array $settings = null): BroadcastRecord
{
    $broadcast = new BroadcastRecord(
        stashId: StashId::parse('stash_01ARZ3NDEKTSV4RRFFQ69G5FAV'),
        type: $type,
        name: $name,
        slug: 'breaking-bad',
        state: BroadcastState::Pending,
        settings: $settings,
    );
    $broadcast->id = new PrimaryKey('broadcast_01ARZ3NDEKTSV4RRFFQ69G5FAV');

    return $broadcast;
}

test('default root is keyed by type and sanitized broadcast name', function (): void {
    $root = sys_get_temp_dir() . '/stashd-broadcast-path-' . bin2hex(random_bytes(4));
    $paths = new BroadcastPathBuilder(pathBuilderTestConfig($root));
    $broadcast = pathBuilderTestBroadcast();

    expect($paths->broadcastRoot($broadcast))
        ->toBe($root . '/media/broadcasts/jellyfin/Breaking Bad');
});

test('destination_path override is the exact literal parent, no inserted type subpath', function (): void {
    $root = sys_get_temp_dir() . '/stashd-broadcast-path-' . bin2hex(random_bytes(4));
    mkdir($root . '/external/TV', 0775, true);
    $paths = new BroadcastPathBuilder(pathBuilderTestConfig($root));
    $broadcast = pathBuilderTestBroadcast(settings: ['destination_path' => $root . '/external/TV']);

    expect($paths->broadcastRoot($broadcast))
        ->toBe($root . '/external/TV/Breaking Bad');
});

test('validateDestinationOverride accepts null and blank as no override', function (): void {
    $paths = new BroadcastPathBuilder(pathBuilderTestConfig(sys_get_temp_dir() . '/stashd-broadcast-path-noop'));

    expect($paths->validateDestinationOverride(null))->toBeNull()
        ->and($paths->validateDestinationOverride('   '))->toBeNull();
});

test('validateDestinationOverride rejects relative paths and traversal', function (string $value): void {
    $paths = new BroadcastPathBuilder(pathBuilderTestConfig(sys_get_temp_dir() . '/stashd-broadcast-path-reject'));

    expect(fn() => $paths->validateDestinationOverride($value))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'relative/path',
    '../outside',
    '/mnt/nas/../etc',
]);

test('validateDestinationOverride rejects paths overlapping a protected storage root', function (): void {
    $root = sys_get_temp_dir() . '/stashd-broadcast-path-' . bin2hex(random_bytes(4));
    mkdir($root . '/media/vault', 0775, true);
    $paths = new BroadcastPathBuilder(pathBuilderTestConfig($root));

    // Exactly the vault root.
    expect(fn() => $paths->validateDestinationOverride($root . '/media/vault'))
        ->toThrow(InvalidArgumentException::class);

    // A descendant of the vault root.
    expect(fn() => $paths->validateDestinationOverride($root . '/media/vault/nested'))
        ->toThrow(InvalidArgumentException::class);

    // An ancestor of the vault root (would make Vault a descendant of the broadcast root).
    expect(fn() => $paths->validateDestinationOverride($root . '/media'))
        ->toThrow(InvalidArgumentException::class);
});

test('claimRoot creates and marks a fresh directory', function (): void {
    $root = sys_get_temp_dir() . '/stashd-broadcast-path-' . bin2hex(random_bytes(4));
    $paths = new BroadcastPathBuilder(pathBuilderTestConfig($root));
    $broadcast = pathBuilderTestBroadcast();

    $claimed = $paths->claimRoot($broadcast);

    expect(is_dir($claimed))->toBeTrue()
        ->and(trim((string) file_get_contents($claimed . '/.stashd-broadcast')))->toBe((string) $broadcast->id);
});

test('claimRoot is a no-op republish when the marker already matches', function (): void {
    $root = sys_get_temp_dir() . '/stashd-broadcast-path-' . bin2hex(random_bytes(4));
    $paths = new BroadcastPathBuilder(pathBuilderTestConfig($root));
    $broadcast = pathBuilderTestBroadcast();

    $first = $paths->claimRoot($broadcast);
    $second = $paths->claimRoot($broadcast);

    expect($second)->toBe($first);
});

test('claimRoot refuses a directory owned by a different broadcast', function (): void {
    $root = sys_get_temp_dir() . '/stashd-broadcast-path-' . bin2hex(random_bytes(4));
    $paths = new BroadcastPathBuilder(pathBuilderTestConfig($root));
    $broadcast = pathBuilderTestBroadcast();
    mkdir($paths->broadcastRoot($broadcast), 0775, true);
    file_put_contents($paths->broadcastRoot($broadcast) . '/.stashd-broadcast', 'broadcast_00000000000000000000000000');

    expect(fn() => $paths->claimRoot($broadcast))
        ->toThrow(BroadcastException::class, 'not managed by this broadcast');
});

test('claimRoot refuses a pre-existing directory with no marker at all', function (): void {
    $root = sys_get_temp_dir() . '/stashd-broadcast-path-' . bin2hex(random_bytes(4));
    $paths = new BroadcastPathBuilder(pathBuilderTestConfig($root));
    $broadcast = pathBuilderTestBroadcast();
    mkdir($paths->broadcastRoot($broadcast), 0775, true);
    file_put_contents($paths->broadcastRoot($broadcast) . '/pre-existing-file.txt', 'not stashd content');

    expect(fn() => $paths->claimRoot($broadcast))
        ->toThrow(BroadcastException::class, 'not managed by this broadcast');
});

test('assertOwnsRoot is a no-op that never creates a directory', function (): void {
    $root = sys_get_temp_dir() . '/stashd-broadcast-path-' . bin2hex(random_bytes(4));
    $paths = new BroadcastPathBuilder(pathBuilderTestConfig($root));
    $broadcast = pathBuilderTestBroadcast();

    $returned = $paths->assertOwnsRoot($broadcast);

    expect($returned)->toBe($paths->broadcastRoot($broadcast))
        ->and(is_dir($returned))->toBeFalse();
});

test('assertOwnsRoot throws the same conflict error as claimRoot for a foreign directory', function (): void {
    $root = sys_get_temp_dir() . '/stashd-broadcast-path-' . bin2hex(random_bytes(4));
    $paths = new BroadcastPathBuilder(pathBuilderTestConfig($root));
    $broadcast = pathBuilderTestBroadcast();
    mkdir($paths->broadcastRoot($broadcast), 0775, true);
    file_put_contents($paths->broadcastRoot($broadcast) . '/.stashd-broadcast', 'broadcast_00000000000000000000000000');

    expect(fn() => $paths->assertOwnsRoot($broadcast))
        ->toThrow(BroadcastException::class, 'not managed by this broadcast');
});
