<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Broadcasts\BroadcastContext;
use App\Broadcasts\BroadcastContextFactory;
use App\Broadcasts\BroadcastException;
use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastItemId;
use App\Broadcasts\BroadcastItemRecord;
use App\Broadcasts\BroadcastItemRepository;
use App\Broadcasts\BroadcastItemState;
use App\Broadcasts\BroadcastPathBuilder;
use App\Broadcasts\BroadcastPlan;
use App\Broadcasts\BroadcastPlugin;
use App\Broadcasts\BroadcastPluginActions;
use App\Broadcasts\BroadcastPluginPolicy;
use App\Broadcasts\BroadcastPluginPresentation;
use App\Broadcasts\BroadcastPluginSourceOptions;
use App\Broadcasts\BroadcastPruneResult;
use App\Broadcasts\BroadcastPublishResult;
use App\Broadcasts\BroadcastRecord;
use App\Broadcasts\BroadcastVerifyResult;
use App\Broadcasts\FileKind;
use App\Broadcasts\HardlinkPublisher;
use App\Broadcasts\PublishedResourceRepository;
use App\Broadcasts\PublishedResourceService;
use App\Broadcasts\UiControl;
use App\Connections\ConnectionRepository;
use App\Connections\ConnectionSecrets;
use App\Stashes\DownloadPolicy;
use App\Stashes\StashItemId;
use App\Support\PrefixedUlid;
use App\System\State\StateTransitionService;
use App\Vault\AssetId;
use App\Vault\AssetKind;
use App\Vault\AssetRecord;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\MediaItemId;
use App\Vault\MediaItemRecord;
use App\Vault\MoveFileIntoVault;
use App\Vault\VaultPathBuilder;
use Tempest\Database\PrimaryKey;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

/** Generic application adapter for manifest-registered Broadcast Components. */
final readonly class ExternalBroadcastPlugin implements BroadcastPlugin, BroadcastPluginActions, BroadcastPluginPolicy, BroadcastPluginPresentation, BroadcastPluginSourceOptions
{
    public function __construct(
        private ExternalBroadcastPluginDefinition $definition,
        /** @var array<string, BroadcastPluginRuntime> */
        private array $runtimes,
        private BroadcastContextFactory $contexts,
        private BroadcastPathBuilder $paths,
        private BroadcastItemRepository $items,
        private StateTransitionService $transitions,
        private PublishedResourceService $publications,
        private PublishedResourceRepository $publicationRecords,
        private AssetRepository $assets,
        private MoveFileIntoVault $mover,
        private VaultPathBuilder $vaultPaths,
        private ConnectionRepository $connections,
        private ConnectionSecrets $connectionSecrets,
        private HardlinkPublisher $hardlinks,
    ) {}

    public function broadcastKeys(): array
    {
        return [$this->definition->logicalKey];
    }

    public function supportedFileKinds(): array
    {
        return array_values(array_filter(array_map(
            static fn(string $kind): ?FileKind => FileKind::tryFrom($kind),
            $this->definition->supportedFileKinds,
        )));
    }

    public function uiControls(): array
    {
        return $this->controls($this->definition->uiOptions);
    }

    public function sourceUiControls(): array
    {
        return $this->controls($this->definition->sourceOptions);
    }

    /** @param array<int, array<string, mixed>> $options
     *  @return list<UiControl> */
    private function controls(array $options): array
    {
        return array_values(array_filter(array_map(
            static function (mixed $option): ?UiControl {
                if (! is_string($option['key'] ?? null) || ! is_string($option['label'] ?? null)) {
                    return null;
                }

                return new UiControl(
                    name: (string) $option['key'],
                    label: $option['label'],
                    type: is_string($option['type'] ?? null) ? $option['type'] : 'text',
                    default: $option['default'] ?? null,
                    options: is_array($option['choices'] ?? null) ? array_values(array_filter($option['choices'], 'is_string')) : [],
                    description: is_string($option['description'] ?? null) ? $option['description'] : null,
                    required: ($option['required'] ?? false) === true,
                );
            },
            $options,
        )));
    }

    public function supportsItemRebuild(): bool
    {
        return $this->definition->supportsItemRebuild;
    }

    public function plan(BroadcastContext $context): BroadcastPlan
    {
        return new BroadcastPlan(
            broadcastId: (string) $context->broadcast->id,
            broadcastRoot: $this->paths->broadcastRoot($context->broadcast),
            files: [],
        );
    }

    public function publish(BroadcastContext $context, BroadcastPlan $plan): BroadcastPublishResult
    {
        $this->paths->claimRoot($context->broadcast);
        $stage = sys_get_temp_dir() . '/stashd-broadcast-plugin-' . bin2hex(random_bytes(8));

        if (! mkdir($stage, 0o775, true) && ! is_dir($stage)) {
            throw BroadcastException::withCode('broadcast_plugin_staging_failed', 'Broadcast plugin staging could not be created.');
        }

        $settings = $this->settings($context);

        if ($this->definition->outputPath !== null) {
            $output = $this->publications->publishFile(
                $context->broadcast,
                $this->definition->outputPath,
                $this->definition->outputMediaType,
                access: 'credential',
            );
            $settings[] = ['key' => 'publication_url', 'value' => ['kind' => 'text', 'value' => $this->publications->url($output)]];
        }
        $this->appendConnectionSettings($context, $settings);
        $items = [];
        $itemStashItemIds = [];
        $stagedAssets = [];
        $failed = [];

        foreach ($this->contexts->publishableStashItems($context) as $stashItem) {
            $media = $context->mediaItems[(string) $stashItem->mediaItemId] ?? null;
            $item = $this->findOrCreateItem(
                $context,
                StashItemId::fromPrimaryKey($stashItem->id),
                $stashItem->mediaItemId,
            );

            if (! $media instanceof MediaItemRecord) {
                $this->failItem($item, 'item_unavailable');
                $failed[] = (string) $stashItem->id;

                continue;
            }

            $resources = $this->resources($context->broadcast, $media, $stage, $stagedAssets);

            if ($resources === []) {
                $this->failItem($item, 'resource_unavailable');
                $failed[] = (string) $stashItem->id;

                continue;
            }

            $items[] = [
                'id' => (string) $item->id,
                'source_reference' => $stashItem->stashInputId === null ? null : (string) $stashItem->stashInputId,
                'title' => $media->title ?? $stashItem->displayTitle ?? 'Untitled',
                'description' => $media->description ?? $stashItem->displayDescription,
                'published_at' => ($media->publishedAt ?? $stashItem->firstSeenAt)?->toNativeDateTime()->format(DATE_RSS),
                'duration_seconds' => null,
                'resources' => $resources,
            ];
            $itemStashItemIds[(string) $item->id] = (string) $stashItem->id;
        }

        try {
            $request = [
                'reference' => (string) $context->broadcast->id,
                'settings' => $settings,
                'sources' => $this->sources($context),
                'items' => $items,
            ];
            $prepared = $this->runtime()->prepare(
                $stage,
                $request,
                $this->definition->prepareHelper === null ? null : $this->definition->helperGrant($this->definition->prepareHelper),
                $this->httpGrants($context),
                getenv('STASHD_BROADCAST_HTTP_FIXTURE_DIR') ?: null,
            );
            $artifacts = $prepared->publication['artifacts'] ?? [];

            if (! is_array($artifacts)) {
                throw BroadcastException::withCode('broadcast_plugin_invalid_output', 'Broadcast plugin returned invalid preparation data.');
            }
            $validArtifacts = [];

            foreach ($artifacts as $artifact) {
                if (is_array($artifact)) {
                    /** @var array<string, mixed> $artifact */
                    $validArtifacts[] = $artifact;
                }
            }
            /** @var list<array<string, mixed>> $validArtifacts */
            $this->promoteDerivedArtifacts($context, $stage, $validArtifacts, $stagedAssets);

            foreach ($items as $itemData) {
                $item = $this->items->findByBroadcastAndStashItem(
                    BroadcastId::fromPrimaryKey($context->broadcast->id),
                    StashItemId::fromPrimaryKey(new PrimaryKey($itemStashItemIds[(string) $itemData['id']])),
                );

                if ($item instanceof BroadcastItemRecord) {
                    $this->readyItem($item);
                }
            }

            $items = [];

            foreach ($this->contexts->publishableStashItems($context) as $stashItem) {
                $media = $context->mediaItems[(string) $stashItem->mediaItemId] ?? null;

                if (! $media instanceof MediaItemRecord) {
                    continue;
                }
                $item = $this->items->findByBroadcastAndStashItem(BroadcastId::fromPrimaryKey($context->broadcast->id), StashItemId::fromPrimaryKey($stashItem->id));

                if (! $item instanceof BroadcastItemRecord || $item->state !== BroadcastItemState::Ready) {
                    continue;
                }
                $items[] = [
                    'id' => (string) $item->id,
                    'source_reference' => $stashItem->stashInputId === null ? null : (string) $stashItem->stashInputId,
                    'title' => $media->title ?? $stashItem->displayTitle ?? 'Untitled',
                    'description' => $media->description ?? $stashItem->displayDescription,
                    'published_at' => ($media->publishedAt ?? $stashItem->firstSeenAt)?->toNativeDateTime()->format(DATE_RSS),
                    'duration_seconds' => null,
                    'resources' => $this->resources($context->broadcast, $media, $stage, $stagedAssets),
                ];
            }

            $result = $this->runtime()->publish($stage, [
                'reference' => (string) $context->broadcast->id,
                'settings' => $settings,
                'sources' => $this->sources($context),
                'items' => $items,
            ], null, $this->httpGrants($context), getenv('STASHD_BROADCAST_HTTP_FIXTURE_DIR') ?: null);
            /** @var array{artifact?: array{reference?: mixed}, files?: mixed} $publication */
            $publication = $result->publication;
            $reference = $publication['artifact']['reference'] ?? null;

            if ($this->definition->outputPath !== null && (! is_string($reference) || ! is_file($stage . '/' . $reference))) {
                throw BroadcastException::withCode('broadcast_plugin_invalid_output', 'Broadcast plugin returned no valid output artifact.');
            }

            if ($this->definition->outputPath !== null) {
                $outputPath = $this->paths->broadcastFile($context->broadcast, ...explode('/', $this->definition->outputPath));
                copy($stage . '/' . $reference, $outputPath);
            }
            $this->publishFiles($context, $publication['files'] ?? [], $stagedAssets);
            $this->runtime()->finalize(
                $stage,
                [
                    'reference' => (string) $context->broadcast->id,
                    'settings' => $settings,
                    'sources' => $this->sources($context),
                    'items' => $items,
                ],
                $publication,
                $this->httpGrants($context),
                getenv('STASHD_BROADCAST_HTTP_FIXTURE_DIR') ?: null,
            );
        } catch (BroadcastException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw BroadcastException::withCode('broadcast_plugin_unavailable', 'External Broadcast plugin execution failed.', $exception);
        } finally {
            $this->removeStage($stage);
        }

        $publishedPaths = $this->definition->outputPath === null
            ? []
            : [$this->paths->broadcastFile($context->broadcast, ...explode('/', $this->definition->outputPath))];

        return new BroadcastPublishResult(1, count($failed), $publishedPaths, $failed);
    }

    public function verify(BroadcastContext $context): BroadcastVerifyResult
    {
        $missing = [];

        if ($this->definition->outputPath !== null) {
            $path = $this->paths->broadcastFile($context->broadcast, ...explode('/', $this->definition->outputPath));

            if (! is_file($path) || ! is_readable($path)) {
                $missing[] = $this->definition->outputPath;
            }
        }

        foreach ($this->items->listForBroadcast(BroadcastId::fromPrimaryKey($context->broadcast->id)) as $item) {
            if ($item->publishedPath !== null && ! is_file($item->publishedPath)) {
                $missing[] = $item->publishedPath;
                $item->lastError = 'broadcast_item_output_missing';

                if ($item->state !== BroadcastItemState::Stale) {
                    $this->transitions->transitionBroadcastItem($item, BroadcastItemState::Stale);
                } else {
                    $this->items->save($item);
                }
            }
        }

        return new BroadcastVerifyResult(count($missing) === 0, count($context->stashItems) - count($missing), count($missing), [], [], $missing);
    }

    public function prune(BroadcastContext $context): BroadcastPruneResult
    {
        $removed = [];

        if ($this->definition->outputPath !== null) {
            $path = $this->paths->broadcastFile($context->broadcast, ...explode('/', $this->definition->outputPath));

            if (is_file($path) && @unlink($path)) {
                $removed[] = $path;
            }
        }

        foreach ($this->items->listForBroadcast(BroadcastId::fromPrimaryKey($context->broadcast->id)) as $item) {
            if ($item->publishedPath !== null && is_file($item->publishedPath) && @unlink($item->publishedPath)) {
                $removed[] = $item->publishedPath;
            }
        }
        $root = $this->paths->claimRoot($context->broadcast);

        foreach (glob($root . '/*') ?: [] as $path) {
            if (basename($path) === '.stashd-broadcast') {
                continue;
            }
            $this->removeGeneratedPath($path, $removed);
        }

        return new BroadcastPruneResult(count($removed), $removed);
    }

    /** @param list<string> $removed */
    private function removeGeneratedPath(string $path, array &$removed): void
    {
        if (is_link($path) || is_file($path)) {
            if (@unlink($path)) {
                $removed[] = $path;
            }

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (glob($path . '/*') ?: [] as $child) {
            $this->removeGeneratedPath($child, $removed);
        }
        @rmdir($path);
    }

    public function acceptsDownloadPolicy(BroadcastRecord $broadcast, DownloadPolicy $policy): bool
    {
        return $policy !== DownloadPolicy::MetadataOnly;
    }

    public function derivedWorkCount(BroadcastContext $context): int
    {
        return 0;
    }

    public function prunesAfterPublish(): bool
    {
        return $this->definition->prunesAfterPublish;
    }

    public function detailFields(BroadcastRecord $broadcast): array
    {
        foreach ($this->publicationRecords->listForBroadcast(BroadcastId::fromPrimaryKey($broadcast->id)) as $resource) {
            if ($this->definition->outputPath !== null && $resource->relativePath === $this->definition->outputPath) {
                $url = $this->publications->url($resource);

                return [['id' => 'published-url', 'label' => 'Published URL', 'value' => $url, 'kind' => 'url', 'link' => $url]];
            }
        }

        return [];
    }

    public function actions(BroadcastRecord $broadcast): array
    {
        /** @var list<array{id: string, label: string, intent: string, confirmation?: bool}> $actions */
        $actions = [];

        foreach ($this->definition->actions as $action) {
            if (! is_string($action['id'] ?? null)
                || ! is_string($action['label'] ?? null)
                || ! is_string($action['intent'] ?? null)) {
                continue;
            }

            $entry = [
                'id' => (string) $action['id'],
                'label' => (string) $action['label'],
                'intent' => (string) $action['intent'],
            ];

            if (isset($action['confirmation']) && is_bool($action['confirmation'])) {
                $entry['confirmation'] = $action['confirmation'];
            }
            /** @var array{id: string, label: string, intent: string, confirmation?: bool} $entry */
            $actions[] = $entry;
        }

        return $actions;
    }

    public function invokeAction(BroadcastRecord $broadcast, string $intent, array $payload = []): array
    {
        $action = null;

        foreach ($this->definition->actions as $candidate) {
            if (($candidate['intent'] ?? null) === $intent) {
                $action = $candidate;

                break;
            }
        }

        if ($action === null) {
            throw BroadcastException::withCode('broadcast_action_unsupported', 'Broadcast action is unsupported.');
        }

        if (is_string($action['operation'] ?? null) && $action['operation'] !== 'rotate-publication-credentials') {
            $context = $this->contexts->build($broadcast);
            $settings = $this->settings($context);
            $this->appendConnectionSettings($context, $settings);

            return $this->runtime()->operation(
                sys_get_temp_dir(),
                [
                    'reference' => (string) $broadcast->id,
                    'settings' => $settings,
                    'items' => [],
                ],
                (string) $action['operation'],
                $this->httpGrants($context),
                getenv('STASHD_BROADCAST_HTTP_FIXTURE_DIR') ?: null,
            );
        }

        if (($action['operation'] ?? null) === 'rotate-publication-credentials') {
            $urls = [];

            foreach ($this->publicationRecords->listForBroadcast(BroadcastId::fromPrimaryKey($broadcast->id)) as $resource) {
                if ($resource->access === 'credential') {
                    $this->publications->rotateCredential($resource);
                    $urls[] = $this->publications->url($resource);
                }
            }

            return ['urls' => $urls];
        }

        throw BroadcastException::withCode('broadcast_action_unsupported', 'Broadcast action is unsupported.');
    }

    /** @param list<array{key: string, value: array{kind: string, value: bool|int|string}}> $settings */
    private function appendConnectionSettings(BroadcastContext $context, array &$settings): void
    {
        if ($this->definition->connectionSettingKey === null) {
            return;
        }
        $connectionId = $context->settings()[$this->definition->connectionSettingKey] ?? null;

        if (! is_string($connectionId) || trim($connectionId) === '') {
            return;
        }
        $connection = $this->connections->find(PrefixedUlid::parse($connectionId));

        if ($connection === null) {
            return;
        }
        $settings[] = ['key' => 'server_url', 'value' => ['kind' => 'text', 'value' => $connection->baseUri]];

        foreach ($connection->settings ?? [] as $key => $value) {
            if (is_scalar($value)) {
                $settings[] = ['key' => $key, 'value' => ['kind' => 'text', 'value' => (string) $value]];
            }
        }

        if ($this->definition->credentialName !== null) {
            $settings[] = ['key' => 'credential_name', 'value' => ['kind' => 'text', 'value' => $this->definition->credentialName]];
        }
    }

    private function runtime(): BroadcastPluginRuntime
    {
        return $this->runtimes['plugin']
            ?? throw BroadcastException::withCode('broadcast_runtime_unavailable', 'Plugin runtime is unavailable.');
    }

    /** @return list<PluginHttpGrant> */
    private function httpGrants(BroadcastContext $context): array
    {
        if ($this->definition->connectionSettingKey === null
            || $this->definition->credentialName === null
            || $this->definition->credentialParameter === null) {
            return [];
        }
        $connectionId = $context->settings()[$this->definition->connectionSettingKey] ?? null;

        if (! is_string($connectionId) || trim($connectionId) === '') {
            return [];
        }
        $connection = $this->connections->find(PrefixedUlid::parse($connectionId));
        $token = $connection === null ? null : $this->connectionSecrets->resolve($connection);

        if ($connection === null || $token === null || trim($token) === '') {
            return [];
        }

        return [new PluginHttpGrant(
            allowedPrefixes: [rtrim($connection->baseUri, '/') . '/'],
            credential: new PluginCredentialGrant(
                name: $this->definition->credentialName,
                value: $token,
                parameter: $this->definition->credentialParameter,
                placement: $this->definition->credentialPlacement,
            ),
        )];
    }

    /** @param array<string, AssetRecord> $stagedAssets */
    private function publishFiles(BroadcastContext $context, mixed $files, array $stagedAssets): void
    {
        if (! is_array($files)) {
            return;
        }
        $root = $this->paths->claimRoot($context->broadcast);

        foreach ($files as $file) {
            if (! is_array($file)
                || ! is_string($file['source_reference'] ?? null)
                || ! is_string($file['relative_path'] ?? null)) {
                throw BroadcastException::withCode('broadcast_plugin_invalid_output', 'Broadcast plugin returned an invalid published file.');
            }
            $relative = $file['relative_path'];

            if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
                throw BroadcastException::withCode('broadcast_plugin_invalid_output', 'Broadcast plugin returned an unsafe published path.');
            }
            $source = $stagedAssets[$file['source_reference']] ?? null;
            $sourcePath = $source instanceof AssetRecord ? $source->path : null;

            if (! $source instanceof AssetRecord || $sourcePath === null || ! is_file($sourcePath)) {
                throw BroadcastException::withCode('broadcast_plugin_invalid_output', 'Broadcast plugin returned an unavailable source resource.');
            }
            $item = null;

            foreach ($this->items->listForBroadcast(BroadcastId::fromPrimaryKey($context->broadcast->id)) as $candidate) {
                if ((string) $candidate->id === ($file['item_id'] ?? '')) {
                    $item = $candidate;

                    break;
                }
            }

            if (! $item instanceof BroadcastItemRecord) {
                throw BroadcastException::withCode('broadcast_plugin_invalid_output', 'Broadcast plugin returned an unknown item.');
            }
            $target = $this->paths->broadcastFile($context->broadcast, ...explode('/', $relative));
            $this->hardlinks->publishHardlink($sourcePath, $target, $root);

            if ($source->role === AssetRole::Subtitle) {
                continue;
            }
            $item->publishedPath = $target;
            $item->lastPublishedAt = DateTime::now(Timezone::UTC);
            $item->lastError = null;
            $this->items->save($item);
            $this->upsertPublishedAsset($context, $item, $source, $target, $relative);

            if ($item->state !== BroadcastItemState::Ready) {
                if ($item->state !== BroadcastItemState::Processing) {
                    $this->transitions->transitionBroadcastItem($item, BroadcastItemState::Processing);
                }
                $this->transitions->transitionBroadcastItem($item, BroadcastItemState::Ready);
            }
        }
    }

    private function upsertPublishedAsset(
        BroadcastContext $context,
        BroadcastItemRecord $item,
        AssetRecord $source,
        string $target,
        string $relative,
    ): void {
        $asset = $this->assets->findByBroadcastItemAndRole(BroadcastItemId::fromPrimaryKey($item->id), AssetRole::Hardlink);
        $sourcePath = $source->path;
        $size = $sourcePath !== null && is_file($sourcePath) ? filesize($sourcePath) : null;

        if ($asset === null) {
            $asset = $this->assets->create(
                mediaItemId: MediaItemId::parse((string) $item->mediaItemId),
                role: AssetRole::Hardlink,
                kind: $source->kind,
                state: AssetState::Ready,
                path: $target,
                relativePath: $relative,
                mimeType: $source->mimeType,
                container: $source->container,
                sizeBytes: is_int($size) ? $size : $source->sizeBytes,
                checksum: $source->checksum,
            );
            $asset->broadcastId = BroadcastId::fromPrimaryKey($context->broadcast->id);
            $asset->broadcastItemId = BroadcastItemId::fromPrimaryKey($item->id);
            $asset->derivedFromAssetId = AssetId::parse((string) $source->id);
            $this->assets->save($asset);

            return;
        }

        $asset->path = $target;
        $asset->relativePath = $relative;
        $asset->derivedFromAssetId = AssetId::parse((string) $source->id);
        $asset->sizeBytes = is_int($size) ? $size : $asset->sizeBytes;
        $asset->missingAt = null;
        $asset->missingReason = null;

        if ($asset->state !== AssetState::Ready) {
            $this->transitions->transitionAsset($asset, AssetState::Ready);
        } else {
            $this->assets->save($asset);
        }
    }

    /** @param array<string, AssetRecord> $stagedAssets
     * @return list<array{reference: string, kind: string, derivation_key: ?string, url: string, media_type: ?string, size_bytes: int}>
     */
    private function resources(BroadcastRecord $broadcast, MediaItemRecord $media, ?string $stage = null, array &$stagedAssets = []): array
    {
        $resources = [];

        foreach (AssetRecord::select()->where('mediaItemId', (string) $media->id)->all() as $asset) {
            if (! $asset instanceof AssetRecord || $asset->state !== AssetState::Ready || $asset->path === null) {
                continue;
            }
            $publication = $this->publications->publishAsset($broadcast, $asset, $asset->mimeType ?? 'application/octet-stream', 'credential');
            $reference = (string) $asset->id;

            if ($stage !== null) {
                $extension = pathinfo($asset->path, PATHINFO_EXTENSION) ?: 'bin';
                $reference = 'resources/' . (string) $asset->id . '.' . $extension;
                $destination = $stage . '/' . $reference;

                if (! is_dir(dirname($destination))) {
                    mkdir(dirname($destination), 0o775, true);
                }

                if (! copy($asset->path, $destination)) {
                    throw BroadcastException::withCode('broadcast_plugin_staging_failed', 'Broadcast asset could not be staged.');
                }
                $stagedAssets[$reference] = $asset;
            }
            $resources[] = [
                'reference' => $reference,
                'kind' => $asset->kind->value,
                'derivation_key' => $asset->derivationKey,
                'url' => $this->publications->url($publication),
                'media_type' => $asset->mimeType,
                'size_bytes' => (int) ($asset->sizeBytes ?? 0),
            ];
        }

        return $resources;
    }

    /** @param list<array<string, mixed>> $artifacts
     * @param  array<string, AssetRecord>  $stagedAssets
     */
    private function promoteDerivedArtifacts(BroadcastContext $context, string $stage, array $artifacts, array $stagedAssets): void
    {
        foreach ($artifacts as $artifact) {
            $reference = $artifact['reference'] ?? null;
            $itemId = $artifact['item_id'] ?? null;
            $sourceReference = $artifact['derived_from_reference'] ?? null;
            $derivationKey = $artifact['derivation_key'] ?? null;
            $kind = $artifact['kind'] ?? null;

            if (! is_string($reference) || ! is_string($itemId) || ! is_string($sourceReference) || ! is_string($derivationKey) || $derivationKey === '' || ! is_string($kind)) {
                throw BroadcastException::withCode('broadcast_plugin_invalid_output', 'Broadcast plugin returned an invalid derived artifact.');
            }
            $source = $stagedAssets[$sourceReference] ?? null;
            $sourceId = $source?->id;
            $stagePath = $stage . '/' . $reference;

            if (! $source instanceof AssetRecord || $sourceId === null || ! is_file($stagePath)) {
                throw BroadcastException::withCode('broadcast_plugin_invalid_output', 'Broadcast plugin returned an unavailable derived artifact.');
            }
            $media = $context->mediaItems[$this->itemMediaId($context, $itemId)] ?? null;

            if (! $media instanceof MediaItemRecord) {
                throw BroadcastException::withCode('broadcast_plugin_invalid_output', 'Broadcast plugin returned an artifact for an unknown item.');
            }
            $assetKind = AssetKind::tryFrom($kind) ?? AssetKind::Other;
            $extension = pathinfo($reference, PATHINFO_EXTENSION) ?: 'bin';
            $derivationDigest = substr(hash('sha256', $derivationKey), 0, 16);
            $destination = $this->vaultPaths->vaultFile((string) $media->providerKey, (string) $media->providerItemId, 'derived-' . $derivationDigest . '.' . $extension);
            $existing = $this->assets->findDerived(MediaItemId::fromPrimaryKey($media->id), $assetKind, $derivationKey);

            if ($existing instanceof AssetRecord
                && $existing->state === AssetState::Ready
                && $existing->path !== null
                && is_file($existing->path)
                && (string) $existing->derivedFromAssetId === (string) $sourceId) {
                continue;
            }

            if ($existing instanceof AssetRecord && $existing->path !== null && is_file($existing->path)) {
                @unlink($existing->path);
            }
            $this->mover->moveIntoPlace($stagePath, $destination);

            if ($existing instanceof AssetRecord) {
                $existing->path = $destination;
                $existing->relativePath = $this->vaultPaths->relativeFile((string) $media->providerKey, (string) $media->providerItemId, basename($destination));
                $existing->mimeType = is_string($artifact['media_type'] ?? null) ? $artifact['media_type'] : null;
                $existing->sizeBytes = filesize($destination) ?: null;
                $existing->checksum = hash_file('sha256', $destination) ?: null;
                $existing->derivedFromAssetId = AssetId::fromPrimaryKey($sourceId);
                $existing->derivationKey = $derivationKey;
                $existing->state = AssetState::Ready;
                $this->assets->save($existing);

                continue;
            }
            $asset = $this->assets->create(
                mediaItemId: MediaItemId::fromPrimaryKey($media->id),
                role: AssetRole::Derived,
                kind: $assetKind,
                state: AssetState::Ready,
                path: $destination,
                relativePath: $this->vaultPaths->relativeFile((string) $media->providerKey, (string) $media->providerItemId, basename($destination)),
                mimeType: is_string($artifact['media_type'] ?? null) ? $artifact['media_type'] : null,
                container: $extension,
                sizeBytes: filesize($destination) ?: null,
                checksum: hash_file('sha256', $destination) ?: null,
                derivationKey: $derivationKey,
            );
            $asset->derivedFromAssetId = AssetId::fromPrimaryKey($sourceId);
            $this->assets->save($asset);
        }
    }

    private function itemMediaId(BroadcastContext $context, string $broadcastItemId): string
    {
        foreach ($this->contexts->publishableStashItems($context) as $stashItem) {
            $item = $this->items->findByBroadcastAndStashItem(BroadcastId::fromPrimaryKey($context->broadcast->id), StashItemId::fromPrimaryKey($stashItem->id));

            if ((string) $item?->id === $broadcastItemId) {
                return (string) $stashItem->mediaItemId;
            }
        }

        return '';
    }

    private function removeStage(string $stage): void
    {
        foreach (glob($stage . '/*') ?: [] as $path) {
            is_dir($path) ? $this->removeStage($path) : @unlink($path);
        }
        @rmdir($stage);
    }

    /** @return list<array{key: string, value: array{kind: string, value: bool|int|string}}> */
    private function settings(BroadcastContext $context): array
    {
        $settings = [];

        foreach ($context->settings() as $key => $value) {
            if (! is_bool($value) && ! is_scalar($value)) {
                continue;
            }
            $settings[] = ['key' => $key, 'value' => is_bool($value)
                ? ['kind' => 'boolean', 'value' => $value]
                : (is_int($value) || is_float($value)
                    ? ['kind' => 'number', 'value' => (int) $value]
                    : ['kind' => 'text', 'value' => (string) $value])];
        }

        return $settings;
    }

    /** @return list<array{reference: string, settings: list<array{key: string, value: array{kind: string, value: bool|int|string}}>}> */
    private function sources(BroadcastContext $context): array
    {
        $sourceSettings = $context->settings()['source_settings'] ?? [];
        $sourceSettings = is_array($sourceSettings) ? $sourceSettings : [];

        return array_map(function ($input) use ($sourceSettings): array {
            $reference = (string) $input->id;
            $settings = is_array($sourceSettings[$reference] ?? null) ? $sourceSettings[$reference] : [];

            return [
                'reference' => $reference,
                'settings' => $this->encodeSettings($settings),
            ];
        }, $context->stashInputs);
    }

    /** @param array<mixed> $settings
     *  @return list<array{key: string, value: array{kind: string, value: bool|int|string}}> */
    private function encodeSettings(array $settings): array
    {
        $encoded = [];

        foreach ($settings as $key => $value) {
            if (! is_bool($value) && ! is_int($value) && ! is_float($value) && ! is_string($value)) {
                continue;
            }
            $encoded[] = ['key' => $key, 'value' => is_bool($value)
                ? ['kind' => 'boolean', 'value' => $value]
                : (is_int($value) || is_float($value)
                    ? ['kind' => 'number', 'value' => (int) $value]
                    : ['kind' => 'text', 'value' => $value])];
        }

        return $encoded;
    }

    private function findOrCreateItem(BroadcastContext $context, StashItemId $stashItemId, MediaItemId $mediaItemId): BroadcastItemRecord
    {
        $existing = $this->items->findByBroadcastAndStashItem(BroadcastId::fromPrimaryKey($context->broadcast->id), $stashItemId);

        return $existing ?? $this->items->create(BroadcastId::fromPrimaryKey($context->broadcast->id), $stashItemId, $mediaItemId);
    }

    private function readyItem(BroadcastItemRecord $item): void
    {
        if ($item->state !== BroadcastItemState::Processing) {
            $this->transitions->transitionBroadcastItem($item, BroadcastItemState::Processing);
        }
        $item->lastPublishedAt = DateTime::now(Timezone::UTC);
        $item->lastError = null;
        $this->items->save($item);
        $this->transitions->transitionBroadcastItem($item, BroadcastItemState::Ready);
    }

    private function failItem(BroadcastItemRecord $item, string $reason): void
    {
        if ($item->state !== BroadcastItemState::Processing && $item->state->canTransitionTo(BroadcastItemState::Processing)) {
            $this->transitions->transitionBroadcastItem($item, BroadcastItemState::Processing);
        }
        $item->lastError = $reason;
        $this->items->save($item);

        if ($item->state->canTransitionTo(BroadcastItemState::Failed)) {
            $this->transitions->transitionBroadcastItem($item, BroadcastItemState::Failed);
        }
    }
}
