<?php

declare(strict_types=1);

namespace App\Vault;

use App\Broadcasts\Api\BroadcastResource;
use App\Broadcasts\BroadcastItemRepository;
use App\Broadcasts\BroadcastRecord;
use App\Broadcasts\BroadcastRepository;
use App\Http\Middleware\RequireAuthMiddleware;
use App\Http\Routing\AllowApiClients;
use App\Stashes\Api\StashResource;
use App\Stashes\StashItemRepository;
use App\Stashes\StashRecord;
use App\Stashes\StashRepository;
use App\Support\Http\QueryPagination;
use App\Vault\Api\AssetResource;
use App\Vault\Api\MediaItemDetailResource;
use App\Vault\Api\MediaItemResource;
use App\Vault\Api\VaultItemSummaryResource;
use App\Config\StashdConfig;
use Tempest\Http\ContentType;
use Tempest\Http\Response;
use Tempest\Http\Responses\NotFound;
use Tempest\Http\Responses\Ok;
use Tempest\Http\Request;
use Tempest\Http\Responses\Json;
use Tempest\Http\Status;
use Tempest\Router\Get;
use Tempest\Router\WithMiddleware;

#[AllowApiClients]
#[WithMiddleware(RequireAuthMiddleware::class)]
final readonly class MediaItemController
{
    public function __construct(
        private MediaItemRepository $mediaItems,
        private AssetRepository $assets,
        private StashItemRepository $stashItems,
        private StashRepository $stashes,
        private BroadcastItemRepository $broadcastItems,
        private BroadcastRepository $broadcasts,
        private StashdConfig $config,
    ) {}

    #[Get('/api/v1/items')]
    public function index(Request $request): Json
    {
        [$limit, $offset] = QueryPagination::parse($request);
        $rawSearch = $request->get('search');
        $rawKind = $request->get('kind');
        $search = is_string($rawSearch) ? trim($rawSearch) : '';
        $kind = is_string($rawKind) ? trim($rawKind) : '';

        return new Json([
            'items' => array_map(
                static fn(VaultItemSummary $item): array => VaultItemSummaryResource::fromRecord($item)->toArray(),
                $this->mediaItems->listVaultSummary($limit, $offset, $search === '' ? null : $search, $kind === '' ? null : $kind),
            ),
            'total' => $this->mediaItems->countVaultSummary($search === '' ? null : $search, $kind === '' ? null : $kind),
            'vault_total' => $this->mediaItems->count(),
            'preserved_size_bytes' => $this->mediaItems->totalPreservedSizeBytes(),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    #[Get('/api/v1/items/{id}')]
    public function show(string $id): Json
    {
        $item = $this->findMediaItem($id);

        if ($item === null) {
            return $this->notFound();
        }

        $mediaItemId = MediaItemId::fromPrimaryKey($item->id);
        $stashIds = array_values(array_unique(array_map(
            static fn($stashItem): string => (string) $stashItem->stashId,
            $this->stashItems->listForMediaItem($mediaItemId),
        )));
        $broadcastIds = array_values(array_unique(array_map(
            static fn($broadcastItem): string => (string) $broadcastItem->broadcastId,
            $this->broadcastItems->listForMediaItem($mediaItemId),
        )));
        $stashesById = $this->stashes->listByIds($stashIds);
        $broadcastsById = $this->broadcasts->listByIds($broadcastIds);

        $stashes = array_values(array_filter(array_map(
            static fn(string $stashId): ?StashRecord => $stashesById[$stashId] ?? null,
            $stashIds,
        )));
        $broadcasts = array_values(array_filter(array_map(
            static fn(string $broadcastId): ?BroadcastRecord => $broadcastsById[$broadcastId] ?? null,
            $broadcastIds,
        )));

        $metadataAsset = $this->assets->findByMediaItemAndRole($mediaItemId, AssetRole::MetadataJson);
        $pluginMetadata = null;
        if ($metadataAsset?->state === AssetState::Ready && $metadataAsset->path !== null && is_file($metadataAsset->path)) {
            $decoded = json_decode((string) file_get_contents($metadataAsset->path), true);
            $pluginMetadata = is_array($decoded) ? $decoded : null;
        }

        return new Json([
            ...MediaItemDetailResource::fromRecord(
                item: $item,
                assets: $this->assets->listReadyPreservedForMediaItem($mediaItemId),
                stashes: $stashes,
                broadcasts: $broadcasts,
                preservedSizeBytes: $this->assets->preservedSizeBytesForMediaItem($mediaItemId),
                pluginMetadata: $pluginMetadata,
            )->toArray(),
        ]);
    }

    #[Get('/api/v1/items/{id}/playback')]
    public function playback(string $id): Response
    {
        $item = $this->findMediaItem($id);

        if ($item === null) return new NotFound();

        $asset = $this->assets->findByMediaItemAndRole(MediaItemId::fromPrimaryKey($item->id), AssetRole::VaultOriginal);

        if ($asset === null || $asset->state !== AssetState::Ready || $asset->path === null || ! is_file($asset->path)) return new NotFound();

        $size = $asset->sizeBytes ?? filesize($asset->path);
        if ($size === false || $size === null) return new NotFound();

        $mediaType = $asset->mimeType ?? match ($asset->kind) {
            AssetKind::Video => 'video/mp4',
            AssetKind::Audio => 'audio/mpeg',
            AssetKind::Image => 'image/jpeg',
            default => 'application/octet-stream',
        };

        return (new Ok())
            ->addHeader(ContentType::HEADER, $mediaType)
            ->addHeader('Content-Length', (string) $size)
            ->addHeader('Accept-Ranges', 'bytes')
            ->addHeader('Content-Disposition', 'inline')
            ->addHeader('X-Accel-Redirect', $this->accelPath($asset->path));
    }

    #[Get('/api/v1/items/{id}/assets')]
    public function assets(string $id): Json
    {
        $mediaItem = $this->findMediaItem($id);

        if ($mediaItem === null) {
            return $this->notFound();
        }

        $mediaItemId = MediaItemId::fromPrimaryKey($mediaItem->id);
        $assets = $this->assets->listForMediaItem($mediaItemId);

        $vaultOriginal = $this->assets->findByMediaItemAndRole($mediaItemId, AssetRole::VaultOriginal);
        $vaultOriginalReady = $vaultOriginal?->state === AssetState::Ready;

        $broadcastNamesById = [];

        foreach ($assets as $asset) {
            if ($asset->broadcastId === null || array_key_exists((string) $asset->broadcastId, $broadcastNamesById)) {
                continue;
            }

            $broadcastNamesById[(string) $asset->broadcastId] = $this->broadcasts->find($asset->broadcastId)?->name;
        }

        return new Json([
            'assets' => array_map(
                fn($asset): array => AssetResource::fromRecord(
                    $asset,
                    AssetRegenerationGuidance::forAsset(
                        asset: $asset,
                        broadcastName: $asset->broadcastId === null ? null : $broadcastNamesById[(string) $asset->broadcastId],
                        vaultOriginalReady: $vaultOriginalReady,
                        mediaItemUpstreamState: $mediaItem->upstreamState,
                    ),
                )->toArray(),
                $assets,
            ),
        ]);
    }

    /** Which stashes contain this media item — no back-reference existed before T12. */
    #[Get('/api/v1/items/{id}/stashes')]
    public function stashes(string $id): Json
    {
        $mediaItem = $this->findMediaItem($id);

        if ($mediaItem === null) {
            return $this->notFound();
        }

        $mediaItemId = MediaItemId::fromPrimaryKey($mediaItem->id);

        $stashIds = array_values(array_unique(array_map(
            static fn($stashItem): string => (string) $stashItem->stashId,
            $this->stashItems->listForMediaItem($mediaItemId),
        )));

        $stashesById = $this->stashes->listByIds($stashIds);
        $stashes = array_values(array_filter(array_map(
            static fn(string $stashId): ?StashRecord => $stashesById[$stashId] ?? null,
            $stashIds,
        )));

        return new Json([
            'stashes' => array_map(
                static fn($stash): array => StashResource::fromRecord($stash)->toArray(),
                $stashes,
            ),
        ]);
    }

    /** Which broadcasts include this media item — no back-reference existed before T12. */
    #[Get('/api/v1/items/{id}/broadcasts')]
    public function broadcasts(string $id): Json
    {
        $mediaItem = $this->findMediaItem($id);

        if ($mediaItem === null) {
            return $this->notFound();
        }

        $mediaItemId = MediaItemId::fromPrimaryKey($mediaItem->id);

        $broadcastIds = array_values(array_unique(array_map(
            static fn($broadcastItem): string => (string) $broadcastItem->broadcastId,
            $this->broadcastItems->listForMediaItem($mediaItemId),
        )));

        $broadcastsById = $this->broadcasts->listByIds($broadcastIds);
        $broadcasts = array_values(array_filter(array_map(
            static fn(string $broadcastId): ?BroadcastRecord => $broadcastsById[$broadcastId] ?? null,
            $broadcastIds,
        )));

        return new Json([
            'broadcasts' => array_map(
                static fn($broadcast): array => BroadcastResource::fromRecord($broadcast)->toArray(),
                $broadcasts,
            ),
        ]);
    }

    private function findMediaItem(string $id): ?MediaItemRecord
    {
        return MediaItemId::isValid($id) ? $this->mediaItems->find(MediaItemId::parse($id)) : null;
    }

    private function notFound(): Json
    {
        return new Json([
            'error' => [
                'code' => 'not_found',
                'message' => 'Media item not found.',
            ],
        ], Status::NOT_FOUND);
    }

    private function accelPath(string $path): string
    {
        $root = rtrim($this->config->mediaPath, '/') . '/';
        $relative = str_starts_with($path, $root) ? substr($path, strlen($root)) : basename($path);

        return '/' . implode('/', array_map(rawurlencode(...), explode('/', $relative)));
    }
}
