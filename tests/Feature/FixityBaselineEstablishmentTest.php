<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastRepository;
use App\Config\StashdConfig;
use App\Fixity\EstablishFixityBaseline;
use App\Fixity\EstablishFixityBaselineOutcome;
use App\Fixity\FixityStatus;
use App\Fixity\FixityStatusResolver;
use App\Fixity\PreservationEventRepository;
use App\Fixity\PreservationEventType;
use App\Fixity\PreservationHealth;
use App\Fixity\PreservationHealthResolver;
use App\Fixity\PreservationOutcome;
use App\Fixity\VerificationCandidateFinder;
use App\Jobs\JobRecord;
use App\Stashes\StashId;
use App\Stashes\StashRepository;
use App\System\Scheduler\VerificationScheduler;
use App\System\Storage\StorageLocationKey;
use App\System\Storage\StorageLocationRepository;
use App\System\Storage\StorageLocationState;
use App\Vault\AssetId;
use App\Vault\AssetKind;
use App\Vault\AssetRecord;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\ItemId;
use App\Vault\ItemRepository;
use Tempest\Console\ExitCode;

final class BaselineChecksumFailureStream
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

function readyBaselineTestVault(StashdConfig $config, StorageLocationRepository $locations): string
{
    $path = $config->vaultPath();

    if (! is_dir($path)) {
        mkdir($path, 0777, true);
    }

    $locations->upsert(
        key: StorageLocationKey::Vault,
        role: StorageLocationKey::Vault,
        label: 'Vault',
        path: $path,
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

    return $path;
}

function createChecksumlessLegacyAsset(ItemRepository $items, AssetRepository $assets, string $vault, string $name, AssetState $state = AssetState::Ready, ?string $path = null): AssetRecord
{
    $item = $items->create('test', 'legacy-baseline-' . $name, 'https://example.test/' . $name, 'Legacy baseline ' . $name);
    $path ??= $vault . '/' . $name . '.bin';

    if ($state === AssetState::Ready && ! str_contains($path, '://')) {
        file_put_contents($path, 'legacy bytes ' . $name);
    }

    return $assets->create(
        itemId: ItemId::fromPrimaryKey($item->id),
        role: AssetRole::VaultOriginal,
        kind: AssetKind::Video,
        state: $state,
        path: $path,
    );
}

test('a retrospective baseline remains unverified then hands off to automatic verification', function (): void {
    $assets = $this->container->get(AssetRepository::class);
    $items = $this->container->get(ItemRepository::class);
    $events = $this->container->get(PreservationEventRepository::class);
    $vault = readyBaselineTestVault(
        $this->container->get(StashdConfig::class),
        $this->container->get(StorageLocationRepository::class),
    );
    $name = 'handoff-' . bin2hex(random_bytes(4));
    $asset = createChecksumlessLegacyAsset($items, $assets, $vault, $name);
    $assetId = AssetId::fromPrimaryKey($asset->id);

    $before = $this->container->get(VerificationCandidateFinder::class)->find();
    expect(array_map(static fn(AssetRecord $candidate): string => (string) $candidate->id, $before->assets))->not->toContain((string) $asset->id)
        ->and($before->unverifiable)->toBe(1);

    $result = $this->container->get(EstablishFixityBaseline::class)->establish($assetId);
    $asset = $assets->find($assetId) ?? throw new \RuntimeException('Expected asset after baseline establishment.');
    $history = $events->listForAsset($assetId);

    expect($result->outcome)->toBe(EstablishFixityBaselineOutcome::Established)
        ->and($asset->checksum)->toBe('sha256:' . hash('sha256', 'legacy bytes ' . $name))
        ->and($asset->checksum)->toBe($result->expectedChecksum)
        ->and($result->observedChecksum)->toBe($asset->checksum)
        ->and($asset->lastVerifiedAt)->toBeNull()
        ->and($this->container->get(FixityStatusResolver::class)->forAsset($asset))->toBe(FixityStatus::Unverified)
        ->and($this->container->get(PreservationHealthResolver::class)->forAsset($asset))->toBe(PreservationHealth::Attention)
        ->and($history)->toHaveCount(1)
        ->and($history[0]->eventType)->toBe(PreservationEventType::FixityGenerated)
        ->and($history[0]->outcome)->toBe(PreservationOutcome::Success)
        ->and($history[0]->expectedChecksum)->toBe($asset->checksum)
        ->and($history[0]->observedChecksum)->toBe($asset->checksum)
        ->and($history[0]->detail)->toBe([
            'source' => 'retrospective_baseline',
            'reason' => 'legacy_asset_without_expected_checksum',
        ]);

    $after = $this->container->get(VerificationCandidateFinder::class)->find();
    expect(array_map(static fn(AssetRecord $candidate): string => (string) $candidate->id, $after->assets))->toContain((string) $asset->id);

    $scheduled = $this->container->get(VerificationScheduler::class)->run();
    expect($scheduled->dispatched)->toBe(1)
        ->and(JobRecord::select()->where('entityId', (string) $asset->id)->first())->not->toBeNull();

    $this->processAllJobs();
    $asset = $assets->find($assetId) ?? throw new \RuntimeException('Expected asset after verification.');

    expect($this->container->get(FixityStatusResolver::class)->forAsset($asset))->toBe(FixityStatus::Verified)
        ->and($this->container->get(PreservationHealthResolver::class)->forAsset($asset))->toBe(PreservationHealth::Healthy);
});

test('the maintenance command establishes a baseline and explains that it is not verification', function (): void {
    $assets = $this->container->get(AssetRepository::class);
    $vault = readyBaselineTestVault(
        $this->container->get(StashdConfig::class),
        $this->container->get(StorageLocationRepository::class),
    );
    $asset = createChecksumlessLegacyAsset(
        $this->container->get(ItemRepository::class),
        $assets,
        $vault,
        'command-' . bin2hex(random_bytes(4)),
    );

    $this->console->call('stashd:fixity-establish-baseline', [(string) $asset->id])
        ->assertExitCode(ExitCode::SUCCESS)
        ->assertSee('Established retrospective SHA-256 baseline')
        ->assertSee('remains unverified');

    expect($assets->find(AssetId::fromPrimaryKey($asset->id))->lastVerifiedAt)->toBeNull();
});

test('baseline establishment refuses existing, ineligible, and projected assets without changing history', function (): void {
    $assets = $this->container->get(AssetRepository::class);
    $items = $this->container->get(ItemRepository::class);
    $events = $this->container->get(PreservationEventRepository::class);
    $vault = readyBaselineTestVault(
        $this->container->get(StashdConfig::class),
        $this->container->get(StorageLocationRepository::class),
    );
    $service = $this->container->get(EstablishFixityBaseline::class);

    $existing = createChecksumlessLegacyAsset($items, $assets, $vault, 'existing-' . bin2hex(random_bytes(4)));
    $existing->checksum = 'sha256:' . str_repeat('a', 64);
    $assets->save($existing);
    $existingId = AssetId::fromPrimaryKey($existing->id);

    expect($service->establish($existingId)->outcome)->toBe(EstablishFixityBaselineOutcome::AlreadyHasBaseline)
        ->and($assets->find($existingId)->checksum)->toBe('sha256:' . str_repeat('a', 64))
        ->and($events->listForAsset($existingId))->toBeEmpty();

    foreach ([AssetState::Stale, AssetState::Missing, AssetState::Pending, AssetState::Processing, AssetState::Failed] as $state) {
        $asset = createChecksumlessLegacyAsset($items, $assets, $vault, $state->value . '-' . bin2hex(random_bytes(4)), $state);
        $id = AssetId::fromPrimaryKey($asset->id);

        expect($service->establish($id)->outcome)->toBe(EstablishFixityBaselineOutcome::NotEligible)
            ->and($assets->find($id)->checksum)->toBeNull()
            ->and($events->listForAsset($id))->toBeEmpty();
    }

    $pathless = $assets->create(
        ItemId::fromPrimaryKey($items->create('test', 'legacy-pathless-' . bin2hex(random_bytes(4)), 'https://example.test/pathless', 'Pathless')->id),
        AssetRole::VaultOriginal,
        AssetKind::Video,
        AssetState::Ready,
    );
    expect($service->establish(AssetId::fromPrimaryKey($pathless->id))->outcome)->toBe(EstablishFixityBaselineOutcome::NotEligible);

    $stash = $this->container->get(StashRepository::class)->create('Retrospective baseline projection');
    $broadcast = $this->container->get(BroadcastRepository::class)->create(StashId::fromPrimaryKey($stash->id), 'test', 'Projection', 'projection');
    $projection = createChecksumlessLegacyAsset($items, $assets, $vault, 'projection-' . bin2hex(random_bytes(4)));
    $projection->broadcastId = BroadcastId::fromPrimaryKey($broadcast->id);
    $assets->save($projection);

    expect($service->establish(AssetId::fromPrimaryKey($projection->id))->outcome)->toBe(EstablishFixityBaselineOutcome::NotEligible)
        ->and($projection->checksum)->toBeNull();
});

test('baseline establishment leaves assets unchanged when storage, files, or checksums are unavailable', function (): void {
    $assets = $this->container->get(AssetRepository::class);
    $items = $this->container->get(ItemRepository::class);
    $events = $this->container->get(PreservationEventRepository::class);
    $config = $this->container->get(StashdConfig::class);
    $locations = $this->container->get(StorageLocationRepository::class);
    $vault = readyBaselineTestVault($config, $locations);
    $service = $this->container->get(EstablishFixityBaseline::class);

    $missing = createChecksumlessLegacyAsset($items, $assets, $vault, 'missing-' . bin2hex(random_bytes(4)));
    unlink($missing->path);
    $missingId = AssetId::fromPrimaryKey($missing->id);

    expect($service->establish($missingId)->outcome)->toBe(EstablishFixityBaselineOutcome::FileUnavailable)
        ->and($assets->find($missingId)->state)->toBe(AssetState::Ready)
        ->and($assets->find($missingId)->checksum)->toBeNull()
        ->and($events->listForAsset($missingId))->toBeEmpty();

    $unavailable = createChecksumlessLegacyAsset($items, $assets, $vault, 'storage-' . bin2hex(random_bytes(4)));
    $locations->upsert(
        StorageLocationKey::Vault,
        StorageLocationKey::Vault,
        'Vault',
        $vault,
        StorageLocationState::Unavailable,
        false,
        false,
        null,
        null,
        null,
        false,
        false,
        'test storage unavailable',
    );
    $unavailableId = AssetId::fromPrimaryKey($unavailable->id);

    expect($service->establish($unavailableId)->outcome)->toBe(EstablishFixityBaselineOutcome::StorageUnavailable)
        ->and($assets->find($unavailableId)->checksum)->toBeNull()
        ->and($events->listForAsset($unavailableId))->toBeEmpty();

    readyBaselineTestVault($config, $locations);
    expect(stream_wrapper_register('stashd-baseline-failing-checksum', BaselineChecksumFailureStream::class))->toBeTrue();
    $failed = createChecksumlessLegacyAsset($items, $assets, $vault, 'checksum-' . bin2hex(random_bytes(4)), path: 'stashd-baseline-failing-checksum://asset');
    $failedId = AssetId::fromPrimaryKey($failed->id);
    set_error_handler(static fn(): bool => true, E_WARNING);

    try {
        $result = $service->establish($failedId);
    } finally {
        restore_error_handler();
        stream_wrapper_unregister('stashd-baseline-failing-checksum');
    }

    expect($result->outcome)->toBe(EstablishFixityBaselineOutcome::ChecksumFailed)
        ->and($assets->find($failedId)->checksum)->toBeNull()
        ->and($events->listForAsset($failedId))->toBeEmpty();
});
