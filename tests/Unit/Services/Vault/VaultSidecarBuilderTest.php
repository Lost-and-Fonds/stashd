<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Vault;

use App\Downloads\DownloadRequest;
use App\Downloads\DownloadResult;
use App\Providers\StashdUri;
use App\Stashes\DownloadPolicy;
use App\Stashes\StashId;
use App\Vault\ItemId;
use App\Vault\VaultSidecarBuilder;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

test('vault sidecar builder produces normalized metadata and source json', function (): void {
    $builder = new VaultSidecarBuilder();
    $capturedAt = DateTime::parse('2026-06-16T12:00:00+00:00', Timezone::UTC);
    $request = new DownloadRequest(
        itemId: ItemId::parse('media_01J00000000000000000000001'),
        stashId: StashId::parse('stash_01J00000000000000000000001'),
        providerKey: 'fake',
        providerItemId: 'demo-episode-1',
        canonicalUri: StashdUri::fake('item/demo-episode-1'),
        downloadPolicy: DownloadPolicy::Video,
        tempDirectory: sys_get_temp_dir(),
        title: 'Demo Episode',
        publishedAt: DateTime::parse('2026-06-15T08:30:00Z', Timezone::UTC),
    );

    $metadata = json_decode($builder->metadata($request, $capturedAt), true, flags: JSON_THROW_ON_ERROR);
    $result = new DownloadResult(
        files: [],
        implementation: 'fake',
        implementationVersion: '4a.0',
        sourceUri: $request->canonicalUri,
        attemptedAt: $capturedAt,
    );
    $source = json_decode($builder->sourceJson($request, $result), true, flags: JSON_THROW_ON_ERROR);

    expect($metadata['schema_version'])->toBe(1)
        ->and($metadata['provider_key'])->toBe('fake')
        ->and($metadata['captured_at'])->toEndWith('Z')
        ->and($metadata['published_at'])->toBe('2026-06-15T08:30:00Z')
        ->and($source['downloader']['implementation'])->toBe('fake')
        ->and($source['downloader']['implementation_version'])->toBe('4a.0')
        ->and($source['attempted_at'])->toEndWith('Z');
});

test('vault sidecar builder redacts secret-like tokens in sensitive fields', function (): void {
    $builder = new VaultSidecarBuilder();
    $capturedAt = DateTime::parse('2026-06-16T12:00:00+00:00', Timezone::UTC);
    $token = str_repeat('x', 40);
    $request = new DownloadRequest(
        itemId: ItemId::parse('media_01J00000000000000000000001'),
        stashId: StashId::parse('stash_01J00000000000000000000001'),
        providerKey: 'fake',
        providerItemId: 'demo-episode-1',
        canonicalUri: StashdUri::parse('fake://item/demo'),
        downloadPolicy: DownloadPolicy::Video,
        tempDirectory: sys_get_temp_dir(),
        title: 'Bearer ' . $token,
    );

    $json = $builder->metadata($request, $capturedAt);

    expect($json)->not->toContain($token)
        ->and($json)->toContain('[REDACTED]')
        ->and($json)->toContain('fake://item/demo');
});
