<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Config\StashdConfig;
use App\System\Storage\StorageLocationKey;
use App\System\Storage\StorageLocationRepository;
use App\System\Storage\StorageLocationState;
use App\Vault\AssetId;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\MediaItemId;
use App\Vault\MediaItemRecord;
use App\Vault\MediaItemState;
use App\Vault\VerifyAssetOutcome;
use App\Vault\VerifyVaultAssets;
use Tempest\Database\PrimaryKey;
use Tempest\Http\Status;

test('item.download command response uses snake_case keys', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('api-contract');

    $download = $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => [
            'media_item_id' => $mediaItemId,
            'stash_id' => $stashId,
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);

    expect($download->body)->toHaveKeys(['command_id', 'jobs'])
        ->and($download->body['jobs'][0])->toHaveKeys(['id', 'intent', 'state']);

    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $download->body['command_id'], headers: $headers);
    $command->assertOk();

    expect($command->body['command']['result'])->toHaveKeys([
        'media_item_id',
        'stash_id',
        'skipped',
        'assets_ready',
        'warnings',
    ]);
});

test('items and assets API responses use snake_case keys', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('api-items');

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => [
            'media_item_id' => $mediaItemId,
            'stash_id' => $stashId,
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $item = $this->http->get('/api/v1/items/' . $mediaItemId, headers: $headers);
    $item->assertOk();

    expect($item->body['item'])->toHaveKeys([
        'provider_key',
        'provider_item_id',
        'canonical_uri',
        'duration_seconds',
        'published_at',
        'created_at',
        'updated_at',
    ])->not->toHaveKey('providerKey');

    $assets = $this->http->get('/api/v1/items/' . $mediaItemId . '/assets', headers: $headers);
    $assets->assertOk();

    expect($assets->body['assets'][0])->toHaveKeys([
        'media_item_id',
        'mime_type',
        'size_bytes',
        'last_verified_at',
    ])->not->toHaveKey('mediaItemId')
        // media_item_id is a MediaItemId value object on the record -- must
        // serialize as a plain string in API output, not leak as {"value": ...}.
        ->and($assets->body['assets'][0]['media_item_id'])->toBe($mediaItemId);
});

test('vault sidecar json files contain normalized metadata and provenance', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('sidecar-json');
    $config = $this->container->get(StashdConfig::class);
    $media = MediaItemRecord::findById(new PrimaryKey($mediaItemId));

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => [
            'media_item_id' => $mediaItemId,
            'stash_id' => $stashId,
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $base = $config->vaultPath() . '/fake/items/' . $media->providerItemId;
    $metadata = json_decode((string) file_get_contents($base . '/metadata.json'), true, flags: JSON_THROW_ON_ERROR);
    $source = json_decode((string) file_get_contents($base . '/source.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($metadata['schema_version'])->toBe(1)
        ->and($metadata['provider_key'])->toBe('fake')
        ->and($metadata['canonical_uri'])->toBe($media->canonicalUri)
        ->and($metadata['captured_at'])->toEndWith('Z')
        ->and($source['source_uri'])->toBe($media->canonicalUri)
        ->and($source['downloader']['implementation'])->toBe('fake')
        ->and($source['downloader']['implementation_version'])->toBe('4a.0')
        ->and($source['attempted_at'])->toEndWith('Z');
});

test('force download returns stable unsupported error', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('force-demo');

    $download = $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => [
            'media_item_id' => $mediaItemId,
            'stash_id' => $stashId,
            'force' => true,
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $download->body['command_id'], headers: $headers);
    expect($command->body['command']['state'])->toBe('failed')
        ->and($command->body['jobs'][0]['last_error'])->toContain('download_force_not_supported');
});

test('second download skips without overwriting vault original bytes', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('idempotent-demo');
    $config = $this->container->get(StashdConfig::class);
    $media = MediaItemRecord::findById(new PrimaryKey($mediaItemId));
    $originalPath = $config->vaultPath() . '/fake/items/' . $media->providerItemId . '/original.fake';

    foreach ([1, 2] as $attempt) {
        $this->http->post('/api/v1/commands', [
            'type' => 'item.download',
            'options' => [
                'media_item_id' => $mediaItemId,
                'stash_id' => $stashId,
            ],
        ], headers: $headers)->assertStatus(Status::CREATED);
        $this->processAllJobs();
    }

    $checksum = hash_file('sha256', $originalPath);
    expect($checksum)->not->toBeFalse();

    $assets = $this->container->get(AssetRepository::class)
        ->findByMediaItemAndRole(
            MediaItemId::parse($mediaItemId),
            AssetRole::VaultOriginal,
        );
    expect($assets?->checksum)->toBe('sha256:' . $checksum);
});

test('retry after failed temp download reuses a clean temp directory', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('temp-retry');
    $config = $this->container->get(StashdConfig::class);

    $failed = $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => [
            'media_item_id' => $mediaItemId,
            'stash_id' => $stashId,
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $jobId = $failed->body['jobs'][0]['id'];
    $tempDir = rtrim($config->tempPath(), '/') . '/downloads/' . $jobId;
    mkdir($tempDir, 0775, true);
    file_put_contents($tempDir . '/partial.bin', 'partial');

    $this->processAllJobs();

    $retry = $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => [
            'media_item_id' => $mediaItemId,
            'stash_id' => $stashId,
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $retry->body['command_id'], headers: $headers);
    expect($command->body['command']['state'])->toBe('completed');

    $retryTemp = rtrim($config->tempPath(), '/') . '/downloads/' . $retry->body['jobs'][0]['id'];
    expect(is_dir($retryTemp))->toBeFalse();
});

test('asset verify detects checksum mismatch distinctly from missing files', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('checksum-drift');
    $assets = $this->container->get(AssetRepository::class);
    $verify = $this->container->get(VerifyVaultAssets::class);

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => [
            'media_item_id' => $mediaItemId,
            'stash_id' => $stashId,
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $original = $assets->findByMediaItemAndRole(
        MediaItemId::parse($mediaItemId),
        AssetRole::VaultOriginal,
    );
    file_put_contents((string) $original?->path, 'corrupted-by-test');

    $outcome = $verify->verifyAsset(AssetId::parse((string) $original?->id));
    $original = $assets->findByMediaItemAndRole(
        MediaItemId::parse($mediaItemId),
        AssetRole::VaultOriginal,
    );

    expect($outcome)->toBe(VerifyAssetOutcome::ChecksumMismatch)
        ->and($original?->state)->toBe(AssetState::Stale)
        ->and($original?->missingReason)->toBe('checksum_mismatch');

    $item = MediaItemRecord::findById(new PrimaryKey($mediaItemId));
    expect($item?->state)->toBe(MediaItemState::Ready);
});

test('missing sidecar metadata does not mark media item missing', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('sidecar-missing');
    $assets = $this->container->get(AssetRepository::class);
    $verify = $this->container->get(VerifyVaultAssets::class);

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => [
            'media_item_id' => $mediaItemId,
            'stash_id' => $stashId,
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $metadata = $assets->findByMediaItemAndRole(
        MediaItemId::parse($mediaItemId),
        AssetRole::MetadataJson,
    );
    unlink((string) $metadata?->path);

    $outcome = $verify->verifyAsset(AssetId::parse((string) $metadata?->id));
    $item = MediaItemRecord::findById(new PrimaryKey($mediaItemId));

    expect($outcome)->toBe(VerifyAssetOutcome::Missing)
        ->and($item?->state)->toBe(MediaItemState::Ready);
});

test('verify vault skips when storage root is unavailable', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('storage-unavailable');
    $config = $this->container->get(StashdConfig::class);
    $storage = $this->container->get(StorageLocationRepository::class);
    $assets = $this->container->get(AssetRepository::class);

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => [
            'media_item_id' => $mediaItemId,
            'stash_id' => $stashId,
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $storage->upsert(
        key: StorageLocationKey::Vault,
        role: StorageLocationKey::Vault,
        label: 'Vault',
        path: $config->vaultPath(),
        state: StorageLocationState::Unavailable,
        readable: false,
        writable: false,
        freeBytes: null,
        totalBytes: null,
        filesystemId: null,
        supportsHardlinks: false,
        supportsSymlinks: false,
        lastError: 'simulated unavailable root',
    );

    $verify = $this->http->post('/api/v1/commands', [
        'type' => 'system.verify_vault',
        'options' => [],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $verify->body['command_id'], headers: $headers);
    expect($command->body['command']['result']['storage_unavailable'])->toBeTrue()
        ->and($command->body['command']['result']['checked'])->toBe(0);

    $original = $assets->findByMediaItemAndRole(
        MediaItemId::parse($mediaItemId),
        AssetRole::VaultOriginal,
    );
    expect($original?->state)->toBe(AssetState::Ready);
});

test('download rejects unavailable temp storage with explicit error', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('temp-unavailable');
    $config = $this->container->get(StashdConfig::class);
    $storage = $this->container->get(StorageLocationRepository::class);

    $storage->upsert(
        key: StorageLocationKey::Temp,
        role: StorageLocationKey::Temp,
        label: 'Temp',
        path: $config->tempPath(),
        state: StorageLocationState::Unwritable,
        readable: true,
        writable: false,
        freeBytes: null,
        totalBytes: null,
        filesystemId: null,
        supportsHardlinks: false,
        supportsSymlinks: false,
        lastError: 'simulated unwritable temp root',
    );

    $download = $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => [
            'media_item_id' => $mediaItemId,
            'stash_id' => $stashId,
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $download->body['command_id'], headers: $headers);
    expect($command->body['command']['state'])->toBe('failed')
        ->and($command->body['jobs'][0]['last_error'])->toContain('storage_unavailable');
});
