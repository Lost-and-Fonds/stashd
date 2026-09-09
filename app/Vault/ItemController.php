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
use App\Stashes\DownloadPolicy;
use App\Stashes\StashItemRepository;
use App\Stashes\StashRecord;
use App\Stashes\StashRepository;
use App\Support\Http\QueryPagination;
use App\Vault\Api\AssetResource;
use App\Vault\Api\ItemDetailResource;
use App\Vault\Api\ItemResource;
use App\Vault\Api\VaultItemSummaryResource;
use App\Config\StashdConfig;
use App\Fixity\FixityStatusResolver;
use App\Fixity\PreservationHealthResolver;
use App\Http\Api\ApiJson;
use App\Jobs\Api\JobResource;
use App\Jobs\JobDispatcher;
use App\Jobs\JobRepository;
use App\Jobs\JobType;
use App\Support\PrefixedUlid;
use Tempest\Http\ContentType;
use Tempest\Http\Response;
use Tempest\Http\Responses\NotFound;
use Tempest\Http\Responses\Ok;
use Tempest\Http\Request;
use Tempest\Http\Responses\Json;
use Tempest\Http\Status;
use Tempest\Router\Get;
use Tempest\Router\Post;
use Tempest\Router\WithMiddleware;

#[AllowApiClients]
#[WithMiddleware(RequireAuthMiddleware::class)]
final readonly class ItemController
{
    public function __construct(
        private ItemRepository $items,
        private AssetRepository $assets,
        private StashItemRepository $stashItems,
        private StashRepository $stashes,
        private BroadcastItemRepository $broadcastItems,
        private BroadcastRepository $broadcasts,
        private StashdConfig $config,
        private JobRepository $jobs,
        private JobDispatcher $jobDispatcher,
        private FixityStatusResolver $fixityStatus,
        private PreservationHealthResolver $preservationHealth,
    ) {}

    #[Get('/api/v1/items')]
    public function index(Request $request): Json
    {
        [$limit, $offset] = QueryPagination::parse($request);
        $rawSearch = $request->get('search');
        $rawKind = $request->get('kind');
        $search = is_string($rawSearch) ? trim($rawSearch) : '';
        $kind = is_string($rawKind) ? trim($rawKind) : '';

        $items = $this->items->listVaultSummary($limit, $offset, $search === '' ? null : $search, $kind === '' ? null : $kind);
        $preservedAssets = $this->assets->listPreservedForItems(array_map(
            static fn(VaultItemSummary $item): string => (string) $item->item->id,
            $items,
        ));
        $healthByItem = $this->preservationHealth->forItems($preservedAssets);

        $items = array_map(
            static fn(VaultItemSummary $item): VaultItemSummary => new VaultItemSummary(
                item: $item->item,
                kind: $item->kind,
                stashCount: $item->stashCount,
                broadcastCount: $item->broadcastCount,
                preservedSizeBytes: $item->preservedSizeBytes,
                preservation: $healthByItem[(string) $item->item->id] ?? null,
            ),
            $items,
        );

        return new Json([
            'items' => array_map(
                static fn(VaultItemSummary $item): array => VaultItemSummaryResource::fromRecord($item)->toArray(),
                $items,
            ),
            'total' => $this->items->countVaultSummary($search === '' ? null : $search, $kind === '' ? null : $kind),
            'vault_total' => $this->items->count(),
            'preserved_size_bytes' => $this->items->totalPreservedSizeBytes(),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    #[Get('/api/v1/items/{id}')]
    public function show(string $id): Json
    {
        $item = $this->findItem($id);

        if ($item === null) {
            return $this->notFound();
        }

        $itemId = ItemId::fromPrimaryKey($item->id);
        $stashIds = array_values(array_unique(array_map(
            static fn($stashItem): string => (string) $stashItem->stashId,
            $this->stashItems->listForItem($itemId),
        )));
        $broadcastIds = array_values(array_unique(array_map(
            static fn($broadcastItem): string => (string) $broadcastItem->broadcastId,
            $this->broadcastItems->listForItem($itemId),
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

        $metadataAsset = $this->assets->findByItemAndRole($itemId, AssetRole::MetadataJson);
        $pluginMetadata = null;

        if ($metadataAsset?->state === AssetState::Ready && $metadataAsset->path !== null && is_file($metadataAsset->path)) {
            $decoded = json_decode((string) file_get_contents($metadataAsset->path), true);
            $pluginMetadata = is_array($decoded) ? ApiJson::normalizeRequest($decoded) : null;
        }

        return new Json([
            ...ItemDetailResource::fromRecord(
                item: $item,
                assets: $this->assets->listReadyPreservedForItem($itemId),
                stashes: $stashes,
                broadcasts: $broadcasts,
                preservedSizeBytes: $this->assets->preservedSizeBytesForItem($itemId),
                pluginMetadata: $pluginMetadata,
                preservation: $this->preservationHealth->forItem($this->assets->listForItem($itemId)),
            )->toArray(),
        ]);
    }

    #[Post('/api/v1/items/{id}/refetch')]
    public function refetch(string $id): Json
    {
        $item = $this->findItem($id);

        if ($item === null) {
            return $this->notFound();
        }

        $itemId = ItemId::fromPrimaryKey($item->id);
        $stashItems = $this->stashItems->listForItem($itemId);
        $stashItem = $stashItems[0] ?? null;

        if (count($stashItems) > 1) {
            $stashes = $this->stashes->listByIds(array_map(static fn($candidate): string => (string) $candidate->stashId, $stashItems));

            foreach ($stashItems as $candidate) {
                if (($stashes[(string) $candidate->stashId] ?? null)?->downloadPolicy !== DownloadPolicy::MetadataOnly) {
                    $stashItem = $candidate;

                    break;
                }
            }
        }

        if ($stashItem === null) {
            return new Json([
                'error' => [
                    'code' => 'stash_item_not_found',
                    'message' => 'Item is not part of a Stash.',
                ],
            ], Status::UNPROCESSABLE_CONTENT);
        }

        $active = $this->jobs->pendingOrProcessing(JobType::core('core.download'), PrefixedUlid::parse((string) $item->id));

        if ($active !== null) {
            return new Json(['job' => JobResource::fromRecord($active)->toArray()], Status::ACCEPTED);
        }

        $job = $this->jobDispatcher->dispatch(
            type: 'core.download',
            entityType: 'item',
            entityId: (string) $item->id,
            stashId: (string) $stashItem->stashId,
            payload: [
                'item_id' => (string) $item->id,
                'stash_id' => (string) $stashItem->stashId,
                'force' => true,
            ],
            workload: 'background',
        );

        return new Json(['job' => JobResource::fromRecord($job)->toArray()], Status::ACCEPTED);
    }

    #[Get('/api/v1/items/{id}/playback')]
    public function playback(string $id): Response
    {
        $item = $this->findItem($id);

        if ($item === null) {
            return new NotFound();
        }

        $asset = $this->assets->findByItemAndRole(ItemId::fromPrimaryKey($item->id), AssetRole::VaultOriginal);

        if ($asset === null || $asset->state !== AssetState::Ready || $asset->path === null || ! is_file($asset->path)) {
            return new NotFound();
        }

        $size = $asset->sizeBytes ?? filesize($asset->path);

        if ($size === false) {
            return new NotFound();
        }

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
        $item = $this->findItem($id);

        if ($item === null) {
            return $this->notFound();
        }

        $itemId = ItemId::fromPrimaryKey($item->id);
        $assets = $this->assets->listForItem($itemId);
        $fixityStatuses = $this->fixityStatus->forAssets($assets);

        $vaultOriginal = $this->assets->findByItemAndRole($itemId, AssetRole::VaultOriginal);
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
                        itemUpstreamState: $item->upstreamState,
                    ),
                    $fixityStatuses[(string) $asset->id] ?? null,
                    $this->preservationHealth->forAsset($asset, $fixityStatuses[(string) $asset->id] ?? null),
                    $this->fixityStatus->verificationDueAt($asset),
                )->toArray(),
                $assets,
            ),
        ]);
    }

    /** Which stashes contain this item — no back-reference existed before T12. */
    #[Get('/api/v1/items/{id}/stashes')]
    public function stashes(string $id): Json
    {
        $item = $this->findItem($id);

        if ($item === null) {
            return $this->notFound();
        }

        $itemId = ItemId::fromPrimaryKey($item->id);

        $stashIds = array_values(array_unique(array_map(
            static fn($stashItem): string => (string) $stashItem->stashId,
            $this->stashItems->listForItem($itemId),
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

    /** Which broadcasts include this item — no back-reference existed before T12. */
    #[Get('/api/v1/items/{id}/broadcasts')]
    public function broadcasts(string $id): Json
    {
        $item = $this->findItem($id);

        if ($item === null) {
            return $this->notFound();
        }

        $itemId = ItemId::fromPrimaryKey($item->id);

        $broadcastIds = array_values(array_unique(array_map(
            static fn($broadcastItem): string => (string) $broadcastItem->broadcastId,
            $this->broadcastItems->listForItem($itemId),
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

    private function findItem(string $id): ?ItemRecord
    {
        return ItemId::isValid($id) ? $this->items->find(ItemId::parse($id)) : null;
    }

    private function notFound(): Json
    {
        return new Json([
            'error' => [
                'code' => 'not_found',
                'message' => 'Item not found.',
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
