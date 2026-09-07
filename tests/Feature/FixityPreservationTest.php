<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Downloads\DownloadItem;
use App\Downloads\DownloadedFile;
use App\Downloads\DownloaderInterface;
use App\Downloads\DownloadException;
use App\Downloads\DownloadProbeResult;
use App\Downloads\DownloadRequest;
use App\Downloads\DownloadResult;
use App\Config\StashdConfig;
use App\Fixity\FixityStatus;
use App\Fixity\FixityStatusResolver;
use App\Fixity\PreservationHealth;
use App\Fixity\PreservationHealthResolver;
use App\Fixity\PreservationHealthService;
use App\Fixity\PreservationEventRepository;
use App\Fixity\PreservationEventType;
use App\Fixity\PreservationOutcome;
use App\Fixity\VerifyAssetOutcome;
use App\Fixity\VerifyVaultAssets;
use App\Jobs\JobRepository;
use App\Stashes\StashId;
use App\Support\PrefixedUlidGenerator;
use App\System\Storage\FilesystemProbe;
use App\Vault\AssetId;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\ItemId;
use App\Vault\ItemRepository;
use App\Vault\ItemState;
use App\System\Storage\StorageLocationKey;
use App\System\Storage\StorageLocationRepository;
use App\System\Storage\StorageLocationState;
use App\Vault\AssetKind;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final class FailingChecksumStream
{
    public mixed $context = null;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
    }

    /** @return array<string, int> */
    public function url_stat(string $path, int $flags): array
    {
        $now = time();

        return [
            'dev' => 1,
            'ino' => 1,
            'mode' => 0100644,
            'nlink' => 1,
            'uid' => 0,
            'gid' => 0,
            'rdev' => -1,
            'size' => 1,
            'atime' => $now,
            'mtime' => $now,
            'ctime' => $now,
            'blksize' => -1,
            'blocks' => -1,
        ];
    }
}

final readonly class FailingChecksumDownloader implements DownloaderInterface
{
    public function implementationName(): string
    {
        return 'failing-checksum-test';
    }

    public function implementationVersion(): ?string
    {
        return 'test';
    }

    public function probe(): DownloadProbeResult
    {
        return new DownloadProbeResult(true, $this->implementationName(), $this->implementationVersion());
    }

    public function download(DownloadRequest $request, ?callable $onProgress = null): DownloadResult
    {
        return new DownloadResult(
            files: [new DownloadedFile(
                tempPath: 'stashd-failing-checksum://asset',
                filename: 'failed.fake',
                role: AssetRole::VaultOriginal,
                kind: AssetKind::Video,
                mimeType: 'application/x-stashd-fake',
                container: 'fake',
                sizeBytes: 1,
            )],
            implementation: $this->implementationName(),
            implementationVersion: $this->implementationVersion(),
            sourceUri: $request->canonicalUri,
            attemptedAt: DateTime::now(Timezone::UTC),
        );
    }

    public function acquireArtifacts(array $item, string $staging, string $mediaKind, array $options = []): array
    {
        throw DownloadException::withCode('captions_unavailable', 'Not used by this test downloader.');
    }
}

test('ingest establishes a baseline and verification records committed-object evidence', function (): void {
    [$headers, $stashId, $itemId] = $this->bootstrapFakeDownloadStash('fixity-ingest');
    $jobId = $this->container->get(PrefixedUlidGenerator::class)->generate('job');

    $this->container->get(DownloadItem::class)->execute(
        itemId: ItemId::parse($itemId),
        stashId: StashId::parse($stashId),
        jobId: $jobId,
    );

    $assets = $this->container->get(AssetRepository::class);
    $asset = $assets->findByItemAndRole(ItemId::parse($itemId), AssetRole::VaultOriginal);
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

    $secondVerification = $this->container->get(VerifyVaultAssets::class)->verifyAsset(AssetId::fromPrimaryKey($asset->id), (string) $jobId);
    $events = $this->container->get(PreservationEventRepository::class)->listForAsset(AssetId::fromPrimaryKey($asset->id));

    expect($secondVerification->outcome)->toBe(VerifyAssetOutcome::Ok)
        ->and($events)->toHaveCount(3)
        ->and($events[2]->eventType)->toBe(PreservationEventType::FixityCheck)
        ->and($events[2]->outcome)->toBe(PreservationOutcome::Success);

    $response = $this->http->get('/api/v1/items/' . $itemId . '/assets', headers: $headers);
    $original = array_values(array_filter($response->body['assets'], static fn(array $candidate): bool => $candidate['role'] === AssetRole::VaultOriginal->value))[0];
    expect($original['fixity_status'])->toBe(FixityStatus::Verified->value)
        ->and($original['preservation_health'])->toBe(PreservationHealth::Healthy->value)
        ->and($original['verification_due_at'])->not->toBeNull();

    $itemResponse = $this->http->get('/api/v1/items/' . $itemId, headers: $headers);
    expect($itemResponse->body['item']['preservation_health'])->toBe(PreservationHealth::Attention->value)
        ->and($itemResponse->body['item']['asset_fixity_counts'][FixityStatus::Verified->value])->toBe(1);
});

test('verification policy uses an exact boundary and item health respects the canonical asset', function (): void {
    [, $stashId, $itemId] = $this->bootstrapFakeDownloadStash('fixity-health-policy');
    $this->container->get(DownloadItem::class)->execute(
        itemId: ItemId::parse($itemId),
        stashId: StashId::parse($stashId),
        jobId: $this->container->get(PrefixedUlidGenerator::class)->generate('job'),
    );

    $assets = $this->container->get(AssetRepository::class);
    $events = $this->container->get(PreservationEventRepository::class);
    $original = $assets->findByItemAndRole(ItemId::parse($itemId), AssetRole::VaultOriginal);
    $verifiedAt = DateTime::parse('2026-01-01T00:00:00Z', Timezone::UTC);
    $assetId = AssetId::fromPrimaryKey($original->id);
    $original->lastVerifiedAt = $verifiedAt;
    $assets->save($original);
    $events->create(
        assetId: $assetId,
        eventType: PreservationEventType::FixityCheck,
        outcome: PreservationOutcome::Success,
        occurredAt: $verifiedAt,
        expectedChecksum: $original->checksum,
        observedChecksum: $original->checksum,
    );

    $fixity = $this->container->get(FixityStatusResolver::class);
    $dueAt = $verifiedAt->plusDays(90);
    expect($fixity->forAsset($original, $dueAt->minusSeconds(1)))->toBe(FixityStatus::Verified)
        ->and($fixity->forAsset($original, $dueAt))->toBe(FixityStatus::Due)
        ->and($this->container->get(PreservationHealthResolver::class)->forAsset($original, FixityStatus::Due))->toBe(PreservationHealth::Attention);

    $original->checksum = null;
    $assets->save($original);
    expect($fixity->forAsset($original, $dueAt))->toBe(FixityStatus::Unverified);

    $original->checksum = 'sha256:' . str_repeat('a', 64);
    $original->state = AssetState::Stale;
    $assets->save($original);
    expect($fixity->forAsset($original, $dueAt))->toBe(FixityStatus::Mismatch);

    $support = $assets->create(
        itemId: ItemId::parse($itemId),
        role: AssetRole::SourceThumbnail,
        kind: AssetKind::Image,
        state: AssetState::Ready,
    );
    $health = $this->container->get(PreservationHealthResolver::class);
    $statuses = [
        (string) $original->id => FixityStatus::Verified,
        (string) $support->id => FixityStatus::Mismatch,
    ];
    expect($health->forItem([$original, $support], $statuses)->health)->toBe(PreservationHealth::Attention);

    $statuses[(string) $original->id] = FixityStatus::Missing;
    expect($health->forItem([$original, $support], $statuses)->health)->toBe(PreservationHealth::Critical);

    $statuses[(string) $original->id] = FixityStatus::Verified;
    $statuses[(string) $support->id] = FixityStatus::Verified;
    expect($health->forItem([$original, $support], $statuses)->health)->toBe(PreservationHealth::Healthy);
});

test('Vault preservation summary aggregates fixity counts and remains evidence-only when storage is unavailable', function (): void {
    [$headers, $stashId, $itemId] = $this->bootstrapFakeDownloadStash('fixity-health-summary');
    $this->container->get(DownloadItem::class)->execute(
        itemId: ItemId::parse($itemId),
        stashId: StashId::parse($stashId),
        jobId: $this->container->get(PrefixedUlidGenerator::class)->generate('job'),
    );

    $assets = $this->container->get(AssetRepository::class);
    $events = $this->container->get(PreservationEventRepository::class);
    $original = $assets->findByItemAndRole(ItemId::parse($itemId), AssetRole::VaultOriginal);
    $verifiedAt = DateTime::now(Timezone::UTC)->minusDays(1);
    $original->lastVerifiedAt = $verifiedAt;
    $assets->save($original);
    $events->create(
        assetId: AssetId::fromPrimaryKey($original->id),
        eventType: PreservationEventType::FixityCheck,
        outcome: PreservationOutcome::Success,
        occurredAt: $verifiedAt,
        expectedChecksum: $original->checksum,
        observedChecksum: $original->checksum,
    );
    $assets->create(
        itemId: ItemId::parse($itemId),
        role: AssetRole::SourceThumbnail,
        kind: AssetKind::Image,
        state: AssetState::Ready,
    );

    $summary = $this->container->get(PreservationHealthService::class)->vaultSummary();
    expect($summary->health)->toBe(PreservationHealth::Attention)
        ->and($summary->totalPreservedAssets)->toBe(4)
        ->and($summary->verifiableAssets)->toBe(3)
        ->and($summary->fixityCounts[FixityStatus::Verified->value])->toBe(1)
        ->and($summary->fixityCounts[FixityStatus::Unverified->value])->toBe(3)
        ->and($summary->healthCounts[PreservationHealth::Healthy->value])->toBe(1)
        ->and($summary->healthCounts[PreservationHealth::Attention->value])->toBe(3)
        ->and($summary->attentionItems)->toBe(1)
        ->and(substr($summary->oldestSuccessfulVerificationAt?->toRfc3339() ?? '', 0, 19))->toBe(substr($verifiedAt->toRfc3339(), 0, 19));

    $before = $original->lastVerifiedAt;
    $this->container->get(StorageLocationRepository::class)->upsert(
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

    $unavailable = $this->container->get(PreservationHealthService::class)->vaultSummary();
    expect($unavailable->health)->toBe(PreservationHealth::Unknown)
        ->and($unavailable->storageUnavailable)->toBeTrue()
        ->and(substr($assets->find(AssetId::fromPrimaryKey($original->id))->lastVerifiedAt?->toRfc3339() ?? '', 0, 19))->toBe(substr($before?->toRfc3339() ?? '', 0, 19));

    $response = $this->http->get('/api/v1/system/health', headers: $headers);
    expect($response->body['preservation']['health'])->toBe(PreservationHealth::Unknown->value)
        ->and($response->body['preservation']['storage_unavailable'])->toBeTrue();
});

test('ingest fails before finalization when the baseline checksum cannot be generated', function (): void {
    [, $stashId, $itemId] = $this->bootstrapFakeDownloadStash('fixity-ingest-checksum-failure');
    $this->container->singleton(DownloaderInterface::class, new FailingChecksumDownloader());
    expect(stream_wrapper_register('stashd-failing-checksum', FailingChecksumStream::class))->toBeTrue();

    $exception = null;
    set_error_handler(static fn(): bool => true, E_WARNING);

    try {
        $this->container->get(DownloadItem::class)->execute(
            itemId: ItemId::parse($itemId),
            stashId: StashId::parse($stashId),
            jobId: $this->container->get(PrefixedUlidGenerator::class)->generate('job'),
        );
    } catch (DownloadException $caught) {
        $exception = $caught;
    } finally {
        restore_error_handler();
        stream_wrapper_unregister('stashd-failing-checksum');
    }

    $assets = $this->container->get(AssetRepository::class);
    $asset = $assets->findByItemAndRole(ItemId::parse($itemId), AssetRole::VaultOriginal);
    $events = $this->container->get(PreservationEventRepository::class)->listForAsset(AssetId::fromPrimaryKey($asset->id));

    expect($exception)->toBeInstanceOf(DownloadException::class)
        ->and($exception?->errorCode)->toBe('checksum_failed')
        ->and($asset->state)->toBe(AssetState::Failed)
        ->and($asset->path)->toBeNull()
        ->and($events)->toBeEmpty();
});

test('mismatch and restoration retain both digests and append history', function (): void {
    [, $stashId, $itemId] = $this->bootstrapFakeDownloadStash('fixity-history');
    $this->container->get(DownloadItem::class)->execute(
        itemId: ItemId::parse($itemId),
        stashId: StashId::parse($stashId),
        jobId: $this->container->get(PrefixedUlidGenerator::class)->generate('job'),
    );

    $assets = $this->container->get(AssetRepository::class);
    $asset = $assets->findByItemAndRole(ItemId::parse($itemId), AssetRole::VaultOriginal);
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
        ->and($asset->missingAt)->toBeNull()
        ->and($asset->missingReason)->toBeNull()
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
    [, $stashId, $itemId] = $this->bootstrapFakeDownloadStash('fixity-edge-cases');
    $this->container->get(DownloadItem::class)->execute(
        itemId: ItemId::parse($itemId),
        stashId: StashId::parse($stashId),
        jobId: $this->container->get(PrefixedUlidGenerator::class)->generate('job'),
    );

    $assets = $this->container->get(AssetRepository::class);
    $events = $this->container->get(PreservationEventRepository::class);
    $asset = $assets->findByItemAndRole(ItemId::parse($itemId), AssetRole::VaultOriginal);
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

    unlink($asset->path);
    $this->container->get(VerifyVaultAssets::class)->verifyAsset(AssetId::fromPrimaryKey($asset->id));
    $asset = $assets->find(AssetId::fromPrimaryKey($asset->id));
    $asset->checksum = null;
    $assets->save($asset);
    file_put_contents($asset->path, $original);

    $unknown = $this->container->get(VerifyVaultAssets::class)->verifyAsset(AssetId::fromPrimaryKey($asset->id));
    $asset = $assets->find(AssetId::fromPrimaryKey($asset->id));
    $unknownEvent = $events->latestForAsset(AssetId::fromPrimaryKey($asset->id));
    $item = $this->container->get(ItemRepository::class)->find(ItemId::parse($itemId));

    expect($unknown->outcome)->toBe(VerifyAssetOutcome::Unverified)
        ->and($unknown->restored)->toBeTrue()
        ->and($unknown->observedChecksum)->not->toBeNull()
        ->and($asset->state)->toBe(AssetState::Ready)
        ->and($asset->missingAt)->toBeNull()
        ->and($asset->missingReason)->toBeNull()
        ->and($asset->lastVerifiedAt)->toBeNull()
        ->and($unknownEvent->outcome)->toBe(PreservationOutcome::Unverified)
        ->and($unknownEvent->observedChecksum)->toBe($unknown->observedChecksum)
        ->and($item->state)->toBe(ItemState::Ready)
        ->and($this->container->get(FixityStatusResolver::class)->forAsset($asset))->toBe(FixityStatus::Unverified);

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

test('bulk verification detects a Vault root that disappears after the last storage check', function (): void {
    [, $stashId, $itemId] = $this->bootstrapFakeDownloadStash('fixity-root-disappears');
    $this->container->get(DownloadItem::class)->execute(
        itemId: ItemId::parse($itemId),
        stashId: StashId::parse($stashId),
        jobId: $this->container->get(PrefixedUlidGenerator::class)->generate('job'),
    );

    $assets = $this->container->get(AssetRepository::class);
    $events = $this->container->get(PreservationEventRepository::class);
    $asset = $assets->findByItemAndRole(ItemId::parse($itemId), AssetRole::VaultOriginal);
    $assetId = AssetId::fromPrimaryKey($asset->id);
    $beforeEvents = count($events->listForAsset($assetId));
    $vaultPath = $this->container->get(StashdConfig::class)->vaultPath();
    $offlinePath = $vaultPath . '.offline-' . bin2hex(random_bytes(4));

    expect(rename($vaultPath, $offlinePath))->toBeTrue();

    try {
        $this->container->get(StorageLocationRepository::class)->upsert(
            key: StorageLocationKey::Vault,
            role: StorageLocationKey::Vault,
            label: 'Vault',
            path: $vaultPath,
            state: StorageLocationState::Ready,
            readable: true,
            writable: true,
            freeBytes: null,
            totalBytes: null,
            filesystemId: null,
            supportsHardlinks: true,
            supportsSymlinks: true,
            lastError: null,
        );

        $result = $this->container->get(VerifyVaultAssets::class)->verifyAll();
    } finally {
        rename($offlinePath, $vaultPath);
    }

    $asset = $assets->find($assetId);

    expect($result->storageUnavailable)->toBeTrue()
        ->and($result->checked)->toBe(0)
        ->and($asset->state)->toBe(AssetState::Ready)
        ->and(count($events->listForAsset($assetId)))->toBe($beforeEvents);
});

test('bulk verification stops when the Vault filesystem identity changes', function (): void {
    [, $stashId, $itemId] = $this->bootstrapFakeDownloadStash('fixity-filesystem-identity');
    $this->container->get(DownloadItem::class)->execute(
        itemId: ItemId::parse($itemId),
        stashId: StashId::parse($stashId),
        jobId: $this->container->get(PrefixedUlidGenerator::class)->generate('job'),
    );

    $assets = $this->container->get(AssetRepository::class);
    $events = $this->container->get(PreservationEventRepository::class);
    $asset = $assets->findByItemAndRole(ItemId::parse($itemId), AssetRole::VaultOriginal);
    $assetId = AssetId::fromPrimaryKey($asset->id);
    $vaultPath = $this->container->get(StashdConfig::class)->vaultPath();
    $currentFilesystemId = $this->container->get(FilesystemProbe::class)->filesystemId($vaultPath);
    $beforeEvents = count($events->listForAsset($assetId));

    expect($currentFilesystemId)->not->toBeNull();

    $this->container->get(StorageLocationRepository::class)->upsert(
        key: StorageLocationKey::Vault,
        role: StorageLocationKey::Vault,
        label: 'Vault',
        path: $vaultPath,
        state: StorageLocationState::Ready,
        readable: true,
        writable: true,
        freeBytes: null,
        totalBytes: null,
        filesystemId: 'changed-' . $currentFilesystemId,
        supportsHardlinks: true,
        supportsSymlinks: true,
        lastError: null,
    );

    $result = $this->container->get(VerifyVaultAssets::class)->verifyAll();
    $asset = $assets->find($assetId);

    expect($result->storageUnavailable)->toBeTrue()
        ->and($result->checked)->toBe(0)
        ->and($asset->state)->toBe(AssetState::Ready)
        ->and(count($events->listForAsset($assetId)))->toBe($beforeEvents);
});
