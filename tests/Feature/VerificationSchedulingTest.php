<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Config\StashdConfig;
use App\Console\VerificationSchedulerTickCommand;
use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastRepository;
use App\Fixity\FixityStatus;
use App\Fixity\FixityStatusResolver;
use App\Fixity\PreservationEventRepository;
use App\Fixity\PreservationEventType;
use App\Fixity\PreservationOutcome;
use App\Fixity\VerificationCandidateFinder;
use App\Jobs\JobRecord;
use App\Jobs\JobRepository;
use App\Jobs\JobState;
use App\Jobs\JobType;
use App\Stashes\StashId;
use App\Stashes\StashRepository;
use App\Support\PrefixedUlid;
use App\Support\PrefixedUlidGenerator;
use App\System\Scheduler\VerificationScheduler;
use App\System\Storage\StorageLocationKey;
use App\System\Storage\StorageLocationRepository;
use App\System\Storage\StorageLocationState;
use App\Vault\AssetId;
use App\Vault\AssetKind;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\ItemId;
use App\Vault\ItemRepository;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;
use Tempest\Console\ExitCode;

test('candidate finder selects only unverified and due participating assets in deterministic order', function (): void {
    $items = $this->container->get(ItemRepository::class);
    $assets = $this->container->get(AssetRepository::class);
    $events = $this->container->get(PreservationEventRepository::class);
    $item = $items->create('test', 'verification-candidates-' . bin2hex(random_bytes(4)), 'https://example.test/candidates', 'Candidates');
    $itemId = ItemId::fromPrimaryKey($item->id);
    $checksum = 'sha256:' . str_repeat('a', 64);
    $now = DateTime::parse('2026-01-01T00:00:00Z', Timezone::UTC);

    $create = function (AssetRole $role, AssetState $state, ?DateTime $createdAt = null) use ($assets, $itemId, $checksum): \App\Vault\AssetRecord {
        $asset = $assets->create($itemId, $role, AssetKind::Video, $state, path: '/vault/' . $role->value, checksum: $checksum);

        if ($createdAt !== null) {
            $asset->createdAt = $createdAt;
            $assets->save($asset);
        }

        return $asset;
    };

    $unverifiedOld = $create(AssetRole::VaultOriginal, AssetState::Ready, DateTime::parse('2024-01-01T00:00:00Z', Timezone::UTC));
    $unverifiedNew = $create(AssetRole::SourceThumbnail, AssetState::Ready, DateTime::parse('2024-01-02T00:00:00Z', Timezone::UTC));
    $due = $create(AssetRole::Subtitle, AssetState::Ready, DateTime::parse('2024-01-03T00:00:00Z', Timezone::UTC));
    $due->lastVerifiedAt = DateTime::parse('2024-01-01T00:00:00Z', Timezone::UTC)->minusDays(1);
    $assets->save($due);
    $events->create(
        assetId: AssetId::fromPrimaryKey($due->id),
        eventType: PreservationEventType::FixityCheck,
        outcome: PreservationOutcome::Success,
        occurredAt: $due->lastVerifiedAt,
        expectedChecksum: $checksum,
        observedChecksum: $checksum,
    );

    $verified = $create(AssetRole::Transcript, AssetState::Ready);
    $verified->lastVerifiedAt = $now->minusDays(1);
    $assets->save($verified);
    $events->create(
        assetId: AssetId::fromPrimaryKey($verified->id),
        eventType: PreservationEventType::FixityCheck,
        outcome: PreservationOutcome::Success,
        occurredAt: $verified->lastVerifiedAt,
        expectedChecksum: $checksum,
        observedChecksum: $checksum,
    );

    $mismatch = $create(AssetRole::MetadataJson, AssetState::Stale);
    $missing = $create(AssetRole::SourceJson, AssetState::Missing);
    $pending = $create(AssetRole::Subtitle, AssetState::Pending);
    $processing = $create(AssetRole::Subtitle, AssetState::Processing);
    $failed = $create(AssetRole::Subtitle, AssetState::Failed);
    $checksumless = $assets->create($itemId, AssetRole::Subtitle, AssetKind::Video, AssetState::Ready, path: '/vault/checksumless');
    $pathless = $assets->create($itemId, AssetRole::Subtitle, AssetKind::Video, AssetState::Ready, checksum: $checksum);
    $stash = $this->container->get(StashRepository::class)->create('Verification projection stash');
    $broadcast = $this->container->get(BroadcastRepository::class)->create(
        stashId: StashId::fromPrimaryKey($stash->id),
        type: 'test',
        name: 'Verification projection',
        slug: 'verification-projection',
    );
    $projection = $create(AssetRole::Subtitle, AssetState::Ready);
    $projection->broadcastId = BroadcastId::fromPrimaryKey($broadcast->id);
    $assets->save($projection);

    $plan = $this->container->get(VerificationCandidateFinder::class)->find($now);

    expect($plan->eligible)->toBe(3)
        ->and($plan->alreadyQueued)->toBe(0)
        ->and($plan->unverifiable)->toBe(2)
        ->and(array_map(static fn($asset): string => (string) $asset->id, $plan->assets))
        ->toBe([(string) $unverifiedOld->id, (string) $unverifiedNew->id, (string) $due->id])
        ->and($mismatch->state)->toBe(AssetState::Stale)
        ->and($missing->state)->toBe(AssetState::Missing)
        ->and($pending->state)->toBe(AssetState::Pending)
        ->and($processing->state)->toBe(AssetState::Processing)
        ->and($failed->state)->toBe(AssetState::Failed)
        ->and($this->container->get(FixityStatusResolver::class)->forAsset($checksumless, $now))->toBe(FixityStatus::Unverified)
        ->and($this->container->get(FixityStatusResolver::class)->forAsset($pathless, $now))->toBe(FixityStatus::Unverified);
});

test('active verification jobs are deduplicated across pending processing and retrying states', function (): void {
    $items = $this->container->get(ItemRepository::class);
    $assets = $this->container->get(AssetRepository::class);
    $jobs = $this->container->get(JobRepository::class);
    $item = $items->create('test', 'verification-active-' . bin2hex(random_bytes(4)), 'https://example.test/active', 'Active verification');
    $itemId = ItemId::fromPrimaryKey($item->id);
    foreach ([JobState::Pending, JobState::Processing, JobState::Retrying] as $state) {
        $asset = $assets->create($itemId, AssetRole::VaultOriginal, AssetKind::Video, AssetState::Ready, path: '/vault/active-' . $state->value, checksum: 'sha256:' . str_repeat('b', 64));
        $job = $jobs->create(
            intent: JobType::core('core.verify_vault'),
            entityType: 'asset',
            entityId: PrefixedUlid::parse((string) $asset->id),
            payload: ['asset_id' => (string) $asset->id],
        );
        $job->state = $state;
        $jobs->save($job);
    }

    $plan = $this->container->get(VerificationCandidateFinder::class)->find();

    expect($plan->eligible)->toBe(0)
        ->and($plan->alreadyQueued)->toBe(3)
        ->and($plan->assets)->toBeEmpty();
});

test('verification scheduling dispatches bounded asset jobs and is idempotent', function (): void {
    $items = $this->container->get(ItemRepository::class);
    $assets = $this->container->get(AssetRepository::class);
    $item = $items->create('test', 'verification-batch-' . bin2hex(random_bytes(4)), 'https://example.test/batch', 'Verification batch');
    $itemId = ItemId::fromPrimaryKey($item->id);

    for ($index = 0; $index < 101; $index++) {
        $assets->create($itemId, AssetRole::VaultOriginal, AssetKind::Video, AssetState::Ready, path: '/vault/batch-' . $index, checksum: 'sha256:' . str_repeat('c', 64));
    }

    $vaultPath = $this->container->get(StashdConfig::class)->vaultPath();
    if (! is_dir($vaultPath)) {
        mkdir($vaultPath, 0777, true);
    }

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
        supportsHardlinks: false,
        supportsSymlinks: false,
        lastError: null,
    );

    $scheduler = $this->container->get(VerificationScheduler::class);
    $first = $scheduler->run(DateTime::now(Timezone::UTC));
    $second = $scheduler->run(DateTime::now(Timezone::UTC));
    $third = $scheduler->run(DateTime::now(Timezone::UTC));

    expect($first->eligible)->toBe(101)
        ->and($first->unverifiable)->toBe(0)
        ->and($first->dispatched)->toBe(100)
        ->and($first->limitReached)->toBeTrue()
        ->and($second->eligible)->toBe(1)
        ->and($second->alreadyQueued)->toBe(100)
        ->and($second->dispatched)->toBe(1)
        ->and($third->eligible)->toBe(0)
        ->and($third->alreadyQueued)->toBe(101)
        ->and($third->dispatched)->toBe(0)
        ->and(JobRecord::select()->where('intent', JobType::core('core.verify_vault')->value)->all())->toHaveCount(101);

    $job = JobRecord::select()->where('intent', JobType::core('core.verify_vault')->value)->first();
    $scheduledAssets = $assets->listForItem($itemId);
    expect($job?->entityType)->toBe('asset')
        ->and($job?->entityId)->toBe($job?->payload['asset_id'] ?? null)
        ->and(array_values(array_unique(array_map(static fn($asset): string => $asset->state->value, $scheduledAssets))))
        ->toBe([AssetState::Ready->value])
        ->and(array_filter($scheduledAssets, static fn($asset): bool => $asset->lastVerifiedAt !== null))
        ->toBeEmpty();
});

test('verification scheduling skips unavailable Vault storage and the scheduled command dispatches asset jobs', function (): void {
    $items = $this->container->get(ItemRepository::class);
    $assets = $this->container->get(AssetRepository::class);
    $item = $items->create('test', 'verification-storage-' . bin2hex(random_bytes(4)), 'https://example.test/storage', 'Storage verification');
    $asset = $assets->create(ItemId::fromPrimaryKey($item->id), AssetRole::VaultOriginal, AssetKind::Video, AssetState::Ready, path: '/vault/scheduled', checksum: 'sha256:' . str_repeat('d', 64));
    $checksumless = $assets->create(ItemId::fromPrimaryKey($item->id), AssetRole::Subtitle, AssetKind::Video, AssetState::Ready, path: '/vault/legacy');
    $pathless = $assets->create(ItemId::fromPrimaryKey($item->id), AssetRole::Subtitle, AssetKind::Video, AssetState::Ready, checksum: 'sha256:' . str_repeat('e', 64));
    $scheduler = $this->container->get(VerificationScheduler::class);
    $vaultPath = $this->container->get(StashdConfig::class)->vaultPath();
    if (! is_dir($vaultPath)) {
        mkdir($vaultPath, 0777, true);
    }

    $this->container->get(StorageLocationRepository::class)->upsert(
        key: StorageLocationKey::Vault,
        role: StorageLocationKey::Vault,
        label: 'Vault',
        path: $vaultPath,
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

    $skipped = $scheduler->run();
    expect($skipped->dispatched)->toBe(0)
        ->and($skipped->skippedStorageUnavailable)->toBeTrue()
        ->and(JobRecord::select()->where('intent', JobType::core('core.verify_vault')->value)->all())->toBeEmpty();

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
        supportsHardlinks: false,
        supportsSymlinks: false,
        lastError: null,
    );

    expect($this->container->get(VerificationSchedulerTickCommand::class)->__invoke())->toBe(ExitCode::SUCCESS)
        ->and(JobRecord::select()->where('entityType', 'asset')->where('entityId', (string) $asset->id)->first())->not->toBeNull();

    $plan = $this->container->get(VerificationCandidateFinder::class)->find();
    expect($plan->eligible)->toBe(0)
        ->and($plan->alreadyQueued)->toBe(1)
        ->and($plan->unverifiable)->toBe(2)
        ->and(JobRecord::select()->where('intent', JobType::core('core.verify_vault')->value)->all())->toHaveCount(1)
        ->and($checksumless->lastVerifiedAt)->toBeNull()
        ->and($pathless->lastVerifiedAt)->toBeNull();
});
