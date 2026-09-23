<?php

declare(strict_types=1);

use App\Config\StashdConfig;
use App\Fixity\PreservationEventRepository;
use App\Fixity\PreservationEventType;
use App\Fixity\PreservationOutcome;
use App\Fixity\VaultChecksum;
use App\System\Storage\StorageLocationKey;
use App\System\Storage\StorageLocationRepository;
use App\System\Storage\StorageLocationState;
use App\Vault\AssetKind;
use App\Vault\AssetId;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\ItemId;
use App\Vault\ItemRepository;
use App\Vault\ItemState;
use App\Vault\UpstreamState;
use Tempest\Core\Tempest;

require '/var/www/html/vendor/autoload.php';
require '/var/www/html/bootstrap/tempest_internal_storage.php';

$container = Tempest::boot(
    root: '/var/www/html',
    internalStorage: tempest_internal_storage(),
);

$items = $container->get(ItemRepository::class);
$assets = $container->get(AssetRepository::class);
$events = $container->get(PreservationEventRepository::class);
$storage = $container->get(StorageLocationRepository::class);
$config = $container->get(StashdConfig::class);
$command = $argv[1] ?? 'seed-success';
$vaultPath = $config->vaultPath();

if (! is_dir($vaultPath) && ! mkdir($vaultPath, 0775, true) && ! is_dir($vaultPath)) {
    throw new RuntimeException("Unable to create Vault path: {$vaultPath}");
}

$hardlinkProbe = "{$vaultPath}/.verification-hardlink-probe";
$hardlinkCopy = "{$hardlinkProbe}.copy";
file_put_contents($hardlinkProbe, 'probe');
$supportsHardlinks = link($hardlinkProbe, $hardlinkCopy);
@unlink($hardlinkProbe);
@unlink($hardlinkCopy);

$storageRecord = $storage->upsert(
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
    supportsHardlinks: $supportsHardlinks,
    supportsSymlinks: false,
    lastError: null,
);

if (in_array($command, ['seed-success', 'seed-mismatch'], true)) {
    $label = $command === 'seed-success' ? 'success' : 'mismatch';
    $originalBytes = "stashd-verification-boundary-{$label}-original-0123456789";
    $path = "{$vaultPath}/verification-boundary-{$label}.bin";
    file_put_contents($path, $originalBytes);
    $expectedChecksum = VaultChecksum::requiredFile($path);

    $item = $items->create(
        providerKey: 'docker-verification-fixture',
        providerItemId: "verification-boundary-{$label}",
        canonicalUri: "fixture://verification-boundary/{$label}",
        title: "Verification boundary {$label}",
        state: ItemState::Ready,
        contentType: 'application/octet-stream',
        upstreamState: UpstreamState::Available,
    );
    $asset = $assets->create(
        itemId: ItemId::fromPrimaryKey($item->id),
        role: AssetRole::VaultOriginal,
        kind: AssetKind::Other,
        state: AssetState::Ready,
        path: $path,
        relativePath: "vault/verification-boundary-{$label}.bin",
        mimeType: 'application/octet-stream',
        container: 'bin',
        sizeBytes: strlen($originalBytes),
        checksum: $expectedChecksum,
    );

    $observedChecksum = null;
    if ($command === 'seed-mismatch') {
        $tamperedBytes = "stashd-verification-boundary-{$label}-tampered-9876543210";
        file_put_contents($path, $tamperedBytes);
        $observedChecksum = VaultChecksum::requiredFile($path);
    }

    echo json_encode([
        'item_id' => (string) $item->id,
        'asset_id' => (string) $asset->id,
        'path' => $path,
        'expected_checksum' => $expectedChecksum,
        'observed_checksum' => $observedChecksum,
        'last_verified_at' => $asset->lastVerifiedAt,
        'storage' => [
            'state' => $storageRecord->state->value,
            'readable' => $storageRecord->readable,
            'writable' => $storageRecord->writable,
        ],
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit;
}

if ($command === 'inspect') {
    $assetId = AssetId::parse($argv[2] ?? '');
    $asset = $assets->find($assetId) ?? throw new RuntimeException('Verification fixture asset not found.');
    $item = $asset->itemId === null ? null : $items->find($asset->itemId);

    echo json_encode([
        'asset' => [
            'id' => (string) $asset->id,
            'item_id' => $asset->itemId === null ? null : (string) $asset->itemId,
            'state' => $asset->state->value,
            'checksum' => $asset->checksum,
            'last_verified_at' => $asset->lastVerifiedAt,
        ],
        'item_state' => $item?->state->value,
        'events' => array_map(static fn($event): array => [
            'id' => (string) $event->id,
            'event_type' => $event->eventType->value,
            'outcome' => $event->outcome->value,
            'expected_checksum' => $event->expectedChecksum,
            'observed_checksum' => $event->observedChecksum,
            'job_id' => $event->jobId,
            'occurred_at' => $event->occurredAt,
        ], $events->listForAsset($assetId)),
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit;
}

throw new RuntimeException('Unknown verification fixture command.');
