<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Broadcasts\BroadcastContext;
use App\Broadcasts\BroadcastContextFactory;
use App\Broadcasts\BroadcastException;
use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastItemRecord;
use App\Broadcasts\BroadcastItemRepository;
use App\Broadcasts\BroadcastItemState;
use App\Broadcasts\BroadcastPathBuilder;
use App\Broadcasts\BroadcastPlan;
use App\Broadcasts\BroadcastPlugin;
use App\Broadcasts\BroadcastPluginActions;
use App\Broadcasts\BroadcastPluginPolicy;
use App\Broadcasts\BroadcastPluginPresentation;
use App\Broadcasts\BroadcastPruneResult;
use App\Broadcasts\BroadcastPublishResult;
use App\Broadcasts\BroadcastRecord;
use App\Broadcasts\BroadcastVerifyResult;
use App\Broadcasts\FileKind;
use App\Broadcasts\PublishedResourceRepository;
use App\Broadcasts\PublishedResourceService;
use App\Broadcasts\UiControl;
use App\Stashes\DownloadPolicy;
use App\Stashes\StashItemId;
use App\System\State\StateTransitionService;
use App\Vault\AssetRecord;
use App\Vault\AssetState;
use App\Vault\MediaItemRecord;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

/** Generic application adapter for manifest-registered Broadcast Components. */
final readonly class ExternalBroadcastPlugin implements
    BroadcastPlugin,
    BroadcastPluginPresentation,
    BroadcastPluginActions,
    BroadcastPluginPolicy
{
    public function __construct(
        private ExternalBroadcastPluginDefinition $definition,
        private PluginHostClient $host,
        private BroadcastContextFactory $contexts,
        private BroadcastPathBuilder $paths,
        private BroadcastItemRepository $items,
        private StateTransitionService $transitions,
        private PublishedResourceService $publications,
        private PublishedResourceRepository $publicationRecords,
    ) {
    }

    public function broadcastKeys(): array
    {
        return [$this->definition->logicalKey];
    }

    public function supportedFileKinds(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $kind): ?FileKind => FileKind::tryFrom($kind),
            $this->definition->supportedFileKinds,
        )));
    }

    public function uiControls(): array
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
            $this->definition->uiOptions,
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
        $output = $this->publications->publishFile(
            $context->broadcast,
            $this->definition->outputPath,
            $this->definition->outputMediaType,
            access: 'credential',
        );
        $settings = $this->settings($context);
        $settings[] = ['key' => 'publication_url', 'value' => ['kind' => 'text', 'value' => $this->publications->url($output)]];
        $items = [];
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

            $resources = $this->resources($context->broadcast, $media);
            if ($resources === []) {
                $this->failItem($item, 'resource_unavailable');
                $failed[] = (string) $stashItem->id;
                continue;
            }

            $this->readyItem($item);
            $items[] = [
                'id' => (string) $item->id,
                'title' => $media->title ?? $stashItem->displayTitle ?? 'Untitled',
                'description' => $media->description ?? $stashItem->displayDescription,
                'published_at' => ($media->publishedAt ?? $stashItem->firstSeenAt)?->toNativeDateTime()->format(DATE_RSS),
                'duration_seconds' => null,
                'resources' => $resources,
            ];
        }

        $stage = sys_get_temp_dir() . '/stashd-broadcast-plugin-' . bin2hex(random_bytes(8));
        if (! mkdir($stage, 0o775, true) && ! is_dir($stage)) {
            throw BroadcastException::withCode('broadcast_plugin_staging_failed', 'Broadcast plugin staging could not be created.');
        }

        try {
            $result = $this->host->publishBroadcast($this->definition->componentPath, $stage, [
                'reference' => (string) $context->broadcast->id,
                'settings' => $settings,
                'items' => $items,
            ]);
            /** @var array{artifact?: array{reference?: mixed}} $publication */
            $publication = $result->publication;
            $reference = $publication['artifact']['reference'] ?? null;
            if (! is_string($reference) || ! is_file($stage . '/' . $reference)) {
                throw BroadcastException::withCode('broadcast_plugin_invalid_output', 'Broadcast plugin returned no valid output artifact.');
            }
            $outputPath = $this->paths->broadcastFile($context->broadcast, ...explode('/', $this->definition->outputPath));
            copy($stage . '/' . $reference, $outputPath);
        } catch (BroadcastException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw BroadcastException::withCode('broadcast_plugin_unavailable', 'External Broadcast plugin execution failed.', $exception);
        } finally {
            foreach (glob($stage . '/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($stage);
        }

        return new BroadcastPublishResult(1, count($failed), [$this->paths->broadcastFile($context->broadcast, ...explode('/', $this->definition->outputPath))], $failed);
    }

    public function verify(BroadcastContext $context): BroadcastVerifyResult
    {
        $path = $this->paths->broadcastFile($context->broadcast, ...explode('/', $this->definition->outputPath));
        $missing = is_file($path) && is_readable($path) ? [] : [$this->definition->outputPath];
        return new BroadcastVerifyResult(count($missing) === 0, count($context->stashItems) - count($missing), count($missing), [], [], $missing);
    }

    public function prune(BroadcastContext $context): BroadcastPruneResult
    {
        $path = $this->paths->broadcastFile($context->broadcast, ...explode('/', $this->definition->outputPath));
        if (is_file($path) && @unlink($path)) {
            return new BroadcastPruneResult(1, [$path]);
        }
        return new BroadcastPruneResult(0, []);
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
            if ($resource->relativePath === $this->definition->outputPath) {
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

    /** @return list<array{reference: string, kind: string, url: string, media_type: ?string, size_bytes: int}> */
    private function resources(BroadcastRecord $broadcast, MediaItemRecord $media): array
    {
        $resources = [];
        foreach (AssetRecord::select()->where('mediaItemId', (string) $media->id)->all() as $asset) {
            if (! $asset instanceof AssetRecord || $asset->state !== AssetState::Ready || $asset->path === null) {
                continue;
            }
            $publication = $this->publications->publishAsset($broadcast, $asset, $asset->mimeType ?? 'application/octet-stream', 'credential');
            $resources[] = [
                'reference' => (string) $asset->id,
                'kind' => $asset->kind->value,
                'url' => $this->publications->url($publication),
                'media_type' => $asset->mimeType,
                'size_bytes' => (int) ($asset->sizeBytes ?? 0),
            ];
        }
        return $resources;
    }

    /** @return list<array{key: string, value: array{kind: string, value: bool|string}}> */
    private function settings(BroadcastContext $context): array
    {
        $settings = [];
        foreach ($context->settings() as $key => $value) {
            if (! is_bool($value) && ! is_scalar($value)) {
                continue;
            }
            $settings[] = ['key' => $key, 'value' => is_bool($value)
                ? ['kind' => 'boolean', 'value' => $value]
                : ['kind' => 'text', 'value' => (string) $value]];
        }
        return $settings;
    }

    private function findOrCreateItem(BroadcastContext $context, StashItemId $stashItemId, \App\Vault\MediaItemId $mediaItemId): BroadcastItemRecord
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
