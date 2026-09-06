<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Downloads\DownloadMediaItem;
use App\Config\StashdConfig;
use App\Fixity\FixityStatus;
use App\Fixity\FixityStatusResolver;
use App\Fixity\PreservationEventRepository;
use App\Fixity\PreservationEventType;
use App\Fixity\PreservationOutcome;
use App\Fixity\VerifyAssetOutcome;
use App\Fixity\VerifyVaultAssets;
use App\Jobs\JobRepository;
use App\Stashes\StashId;
use App\Support\PrefixedUlidGenerator;
use App\Vault\AssetId;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\MediaItemId;
use App\System\Storage\StorageLocationKey;
use App\System\Storage\StorageLocationRepository;
use App\System\Storage\StorageLocationState;

test('ingest establishes a baseline and verification records committed-object evidence', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('fixity-ingest');
    $jobId = $this->container->get(PrefixedUlidGenerator::class)->generate('job');

    $this->container->get(DownloadMediaItem::class)->execute(
        mediaItemId: MediaItemId::parse($mediaItemId),
        stashId: StashId::parse($stashId),
        jobId: $jobId,
    );

    $assets = $this->container->get(AssetRepository::class);
    $asset = $assets->findByMediaItemAndRole(MediaItemId::parse($mediaItemId), AssetRole::VaultOriginal);
    expect($asset)->not->toBeNull();

    $events = $this->container->get(PreservationEventRepository::class)->listForAsset(AssetId::fromPrimaryKey($asset->id));
    expect($events)->toHaveCount(1)
        ->and($events[0]->eventType)->toBe(PreservationEventType::FixityGenerated)
        ->and($events[0]->outcome)->toBe(PreservationOutcome::Success)
        ->and($events[0]->expectedChecksum)->toBe($asset->checksum)
        ->and($events[0]->observedChecksum)->toBe($asset->checksum)
        ->and($asset->lastVerifiedAt)->toBeNull();

    expect($this->container->get(FixityStatusResolver::class)->forAsset($asset))->toBe(FixityStatus::Unverified);

    $verification = $this->container->get(VerifyVaultAssets::class)->verifyAsset(AssetId::fromPrimaryKey($asset->id), (string) $jobId);
    $asset = $assets->find(AssetId::fromPrimaryKey($asset->id));
    $events = $this->container->get(PreservationEventRepository::class)->listForAsset(AssetId::fromPrimaryKey($asset->id));

    expect($verification->outcome)->toBe(VerifyAssetOutcome::Ok)
        ->and($verification->expectedChecksum)->toBe($asset->checksum)
        ->and($verification->observedChecksum)->toBe($asset->checksum)
        ->and($asset->lastVerifiedAt)->not->toBeNull()
        ->and($events)->toHaveCount(2)
        ->and($events[1]->eventType)->toBe(PreservationEventType::FixityCheck)
        ->and($events[1]->outcome)->toBe(PreservationOutcome::Success);

    $response = $this->http->get('/api/v1/items/' . $mediaItemId . '/assets', headers: $headers);
    $original = array_values(array_filter($response->body['assets'], static fn(array $candidate): bool => $candidate['role'] === AssetRole::VaultOriginal->value))[0];
    expect($original['fixity_status'])->toBe(FixityStatus::Verified->value);
});

test('mismatch and restoration retain both digests and append history', function (): void {
    [, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('fixity-history');
    $this->container->get(DownloadMediaItem::class)->execute(
        mediaItemId: MediaItemId::parse($mediaItemId),
        stashId: StashId::parse($stashId),
        jobId: $this->container->get(PrefixedUlidGenerator::class)->generate('job'),
    );

    $assets = $this->container->get(AssetRepository::class);
    $asset = $assets->findByMediaItemAndRole(MediaItemId::parse($mediaItemId), AssetRole::VaultOriginal);
    $expected = $asset->checksum;
    $original = file_get_contents($asset->path);
    file_put_contents($asset->path, 'tampered');

    $verification = $this->container->get(VerifyVaultAssets::class)->verifyAsset(AssetId::fromPrimaryKey($asset->id));
    $asset = $assets->find(AssetId::fromPrimaryKey($asset->id));
    $events = $this->container->get(PreservationEventRepository::class)->listForAsset(AssetId::fromPrimaryKey($asset->id));

    expect($verification->outcome)->toBe(VerifyAssetOutcome::ChecksumMismatch)
        ->and($verification->expectedChecksum)->toBe($expected)
        ->and($verification->observedChecksum)->toBe('sha256:' . hash('sha256', 'tampered'))
        ->and($asset->checksum)->toBe($expected)
        ->and($asset->state)->toBe(AssetState::Stale)
        ->and($this->container->get(FixityStatusResolver::class)->forAsset($asset))->toBe(FixityStatus::Mismatch)
        ->and($events)->toHaveCount(2)
        ->and($events[1]->outcome)->toBe(PreservationOutcome::Mismatch)
        ->and($events[1]->expectedChecksum)->toBe($expected)
        ->and($events[1]->observedChecksum)->toBe($verification->observedChecksum);

    file_put_contents($asset->path, $original);
    $restored = $this->container->get(VerifyVaultAssets::class)->verifyAll();
    $asset = $assets->find(AssetId::fromPrimaryKey($asset->id));
    $events = $this->container->get(PreservationEventRepository::class)->listForAsset(AssetId::fromPrimaryKey($asset->id));

    expect($restored->restored)->toBeGreaterThanOrEqual(1)
        ->and($asset->state)->toBe(AssetState::Ready)
        ->and($this->container->get(FixityStatusResolver::class)->forAsset($asset))->toBe(FixityStatus::Verified)
        ->and($events)->toHaveCount(3)
        ->and($events[2]->outcome)->toBe(PreservationOutcome::Success);
});

test('missing files, checksumless assets, and unavailable storage keep distinct semantics', function (): void {
    [, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('fixity-edge-cases');
    $this->container->get(DownloadMediaItem::class)->execute(
        mediaItemId: MediaItemId::parse($mediaItemId),
        stashId: StashId::parse($stashId),
        jobId: $this->container->get(PrefixedUlidGenerator::class)->generate('job'),
    );

    $assets = $this->container->get(AssetRepository::class);
    $events = $this->container->get(PreservationEventRepository::class);
    $asset = $assets->findByMediaItemAndRole(MediaItemId::parse($mediaItemId), AssetRole::VaultOriginal);
    $original = file_get_contents($asset->path);
    unlink($asset->path);

    $missing = $this->container->get(VerifyVaultAssets::class)->verifyAsset(AssetId::fromPrimaryKey($asset->id));
    $missingAsset = $assets->find(AssetId::fromPrimaryKey($asset->id)) ?? throw new \RuntimeException('Missing test asset was not found.');
    expect($missing->outcome)->toBe(VerifyAssetOutcome::Missing)
        ->and($this->container->get(FixityStatusResolver::class)->forAsset($missingAsset))->toBe(FixityStatus::Missing)
        ->and($events->latestForAsset(AssetId::fromPrimaryKey($asset->id))->outcome)->toBe(PreservationOutcome::Missing);

    file_put_contents($asset->path, $original);
    $this->container->get(VerifyVaultAssets::class)->verifyAll();
    $asset = $assets->find(AssetId::fromPrimaryKey($asset->id));
    expect($asset->state)->toBe(AssetState::Ready);

    $checksumless = $assets->create(
        mediaItemId: MediaItemId::parse($mediaItemId),
        role: AssetRole::SourceThumbnail,
        kind: $asset->kind,
        state: AssetState::Ready,
        path: $asset->path,
    );
    $unknown = $this->container->get(VerifyVaultAssets::class)->verifyAsset(AssetId::fromPrimaryKey($checksumless->id));
    $checksumless = $assets->find(AssetId::fromPrimaryKey($checksumless->id));
    $unknownEvent = $events->latestForAsset(AssetId::fromPrimaryKey($checksumless->id));

    expect($unknown->outcome)->toBe(VerifyAssetOutcome::Unverified)
        ->and($unknown->observedChecksum)->not->toBeNull()
        ->and($checksumless->lastVerifiedAt)->toBeNull()
        ->and($unknownEvent->outcome)->toBe(PreservationOutcome::Unverified)
        ->and($this->container->get(FixityStatusResolver::class)->forAsset($checksumless))->toBe(FixityStatus::Unverified);

    $vault = $this->container->get(StorageLocationRepository::class)->upsert(
        key: StorageLocationKey::Vault,
        role: StorageLocationKey::Vault,
        label: 'Vault',
        path: $this->container->get(StashdConfig::class)->vaultPath(),
        state: StorageLocationState::Unavailable,
        readable: false,
        writable: false,
        freeBytes: null,
        totalBytes: null,
        filesystemId: null,
        supportsHardlinks: false,
        supportsSymlinks: false,
        lastError: 'test storage unavailable',
    );
    $before = count($events->listForAsset(AssetId::fromPrimaryKey($asset->id)));
    $bulk = $this->container->get(VerifyVaultAssets::class)->verifyAll();
    $single = $this->container->get(VerifyVaultAssets::class)->verifyAsset(AssetId::fromPrimaryKey($asset->id));

    expect($bulk->storageUnavailable)->toBeTrue()
        ->and($single->outcome)->toBe(VerifyAssetOutcome::StorageUnavailable)
        ->and(count($events->listForAsset(AssetId::fromPrimaryKey($asset->id))))->toBe($before)
        ->and($assets->find(AssetId::fromPrimaryKey($asset->id))->state)->toBe(AssetState::Ready);
});
