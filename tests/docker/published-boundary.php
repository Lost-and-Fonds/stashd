<?php

declare(strict_types=1);

use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastItemRepository;
use App\Broadcasts\BroadcastPathBuilder;
use App\Broadcasts\BroadcastRepository;
use App\Broadcasts\PublishedResourceRepository;
use App\Broadcasts\PublishedResourceService;
use App\Config\StashdConfig;
use App\Stashes\DownloadPolicy;
use App\Stashes\StashItemRepository;
use App\Stashes\StashRepository;
use App\System\Storage\StorageLocationKey;
use App\System\Storage\StorageLocationRepository;
use App\System\Storage\StorageLocationState;
use App\Fixity\VaultChecksum;
use App\Vault\AssetKind;
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

$command = $argv[1] ?? 'seed';
$config = $container->get(StashdConfig::class);
$vaultPath = $config->vaultPath();

if (! is_dir($vaultPath) && ! mkdir($vaultPath, 0775, true) && ! is_dir($vaultPath)) {
    throw new RuntimeException("Unable to create Vault path: {$vaultPath}");
}

$storage = $container->get(StorageLocationRepository::class);
$hardlinkProbe = "{$vaultPath}/.published-hardlink-probe";
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

if ($command === 'seed') {
    $bytes = 'stashd-published-resource-boundary-fixture-0123456789';
    $path = "{$vaultPath}/published-boundary.mp4";
    file_put_contents($path, $bytes);
    $checksum = VaultChecksum::requiredFile($path);

    $stashes = $container->get(StashRepository::class);
    $stashItems = $container->get(StashItemRepository::class);
    $items = $container->get(ItemRepository::class);
    $assets = $container->get(AssetRepository::class);

    $stash = $stashes->create(
        name: 'Published resource boundary fixture',
        downloadPolicy: DownloadPolicy::Video,
    );
    $item = $items->create(
        providerKey: 'docker-published-resource-fixture',
        providerItemId: 'published-resource-boundary',
        canonicalUri: 'fixture://published-resource-boundary',
        title: 'Published resource boundary fixture',
        state: ItemState::Ready,
        contentType: 'video/mp4',
        sizeBytes: strlen($bytes),
        upstreamState: UpstreamState::Available,
    );
    $stashItem = $stashItems->create(
        stashId: \App\Stashes\StashId::fromPrimaryKey($stash->id),
        itemId: ItemId::fromPrimaryKey($item->id),
        position: 1,
    );
    $asset = $assets->create(
        itemId: ItemId::fromPrimaryKey($item->id),
        role: AssetRole::VaultOriginal,
        kind: AssetKind::Video,
        state: AssetState::Ready,
        path: $path,
        relativePath: 'vault/published-boundary.mp4',
        mimeType: 'video/mp4',
        container: 'mp4',
        sizeBytes: strlen($bytes),
        checksum: $checksum,
    );

    echo json_encode([
        'stash_id' => (string) $stash->id,
        'stash_item_id' => (string) $stashItem->id,
        'item_id' => (string) $item->id,
        'asset_id' => (string) $asset->id,
        'vault_path' => $path,
        'fixture_size' => strlen($bytes),
        'fixture_checksum' => $checksum,
        'storage' => [
            'state' => $storageRecord->state->value,
            'readable' => $storageRecord->readable,
            'writable' => $storageRecord->writable,
            'supports_hardlinks' => $storageRecord->supportsHardlinks,
        ],
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit;
}

if ($command === 'register') {
    $broadcastId = BroadcastId::parse($argv[2] ?? '');
    $broadcasts = $container->get(BroadcastRepository::class);
    $broadcastItems = $container->get(BroadcastItemRepository::class);
    $paths = $container->get(BroadcastPathBuilder::class);
    $publications = $container->get(PublishedResourceService::class);
    $broadcast = $broadcasts->find($broadcastId)
        ?? throw new RuntimeException('Published resource fixture broadcast not found.');
    $items = $broadcastItems->listForBroadcast($broadcastId);

    if (count($items) !== 1 || $items[0]->publishedPath === null) {
        throw new RuntimeException('Filesystem broadcast did not produce exactly one published item.');
    }

    $root = rtrim($paths->broadcastRoot($broadcast), '/') . '/';
    $generatedPath = $items[0]->publishedPath;

    if (! str_starts_with($generatedPath, $root)) {
        throw new RuntimeException('Generated broadcast path escaped its broadcast root.');
    }

    $relativePath = substr($generatedPath, strlen($root));
    $resource = $publications->publishFile(
        broadcast: $broadcast,
        relativePath: $relativePath,
        mediaType: 'video/mp4',
        access: 'public',
        downloadName: 'published-boundary.mp4',
    );
    $source = $publications->source($resource);
    $checksum = hash_file('sha256', $source['path']);

    if ($checksum === false) {
        throw new RuntimeException('Generated published resource is not hashable.');
    }

    echo json_encode([
        'broadcast_id' => (string) $broadcast->id,
        'broadcast_state' => $broadcast->state->value,
        'generated_path' => $generatedPath,
        'relative_path' => $relativePath,
        'generated_size' => $source['size'],
        'generated_checksum' => 'sha256:' . $checksum,
        'publication_id' => (string) $resource->id,
        'publication_state' => $resource->state,
        'publication_access' => $resource->access,
        'publication_url' => $publications->url($resource),
        'publication_media_type' => $resource->mediaType,
        'publication_download_name' => $resource->downloadName,
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit;
}

if ($command === 'inspect') {
    $publicationId = $argv[2] ?? '';
    $publications = $container->get(PublishedResourceRepository::class);
    $resource = $publications->find($publicationId)
        ?? throw new RuntimeException('Published resource fixture not found.');

    echo json_encode([
        'publication_id' => (string) $resource->id,
        'broadcast_id' => (string) $resource->broadcastId,
        'relative_path' => $resource->relativePath,
        'state' => $resource->state,
        'access' => $resource->access,
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit;
}

throw new RuntimeException('Unknown published resource fixture command.');
