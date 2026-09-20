<?php

declare(strict_types=1);

use App\Vault\AssetKind;
use App\Vault\AssetRecord;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\ItemId;
use App\Vault\ItemRepository;
use App\Vault\ItemState;
use App\Vault\UpstreamState;
use Tempest\Core\Tempest;
use Tempest\Database\PrimaryKey;

require '/var/www/html/vendor/autoload.php';
require '/var/www/html/bootstrap/tempest_internal_storage.php';

$container = Tempest::boot(
    root: '/var/www/html',
    internalStorage: tempest_internal_storage(),
);

$items = $container->get(ItemRepository::class);
$assets = $container->get(AssetRepository::class);
$command = $argv[1] ?? 'create';

if ($command === 'create') {
    $path = '/media/vault/playback-boundary.mp4';
    $size = filesize($path);
    $checksum = hash_file('sha256', $path);

    if ($size === false || $checksum === false) {
        throw new RuntimeException('Playback fixture is missing or unreadable.');
    }

    $item = $items->create(
        providerKey: 'docker-fixture',
        providerItemId: 'playback-boundary',
        canonicalUri: 'fixture://playback-boundary',
        title: 'Playback boundary fixture',
        state: ItemState::Ready,
        contentType: 'video/mp4',
        sizeBytes: $size,
        upstreamState: UpstreamState::Available,
    );
    $asset = $assets->create(
        itemId: ItemId::fromPrimaryKey($item->id),
        role: AssetRole::VaultOriginal,
        kind: AssetKind::Video,
        state: AssetState::Ready,
        path: $path,
        relativePath: 'vault/playback-boundary.mp4',
        mimeType: 'video/mp4',
        container: 'mp4',
        sizeBytes: $size,
        checksum: 'sha256:' . $checksum,
    );

    echo json_encode([
        'item_id' => (string) $item->id,
        'asset_id' => (string) $asset->id,
        'size' => $size,
        'checksum' => $checksum,
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit;
}

if ($command === 'set-path') {
    $asset = AssetRecord::findById(new PrimaryKey($argv[2] ?? ''))
        ?? throw new RuntimeException('Playback fixture asset not found.');
    $asset->path = $argv[3] ?? throw new RuntimeException('Playback path is required.');
    $assets->save($asset);
    exit;
}

throw new RuntimeException('Unknown playback fixture command.');
