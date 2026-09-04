<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\Broadcasts\Api\BroadcastItemResource;
use App\Broadcasts\Api\BroadcastResource;
use App\Http\Api\ApiJson;
use App\Http\Middleware\RequireAuthMiddleware;
use App\Http\Routing\AllowApiClients;
use App\Jobs\JobType;
use App\Jobs\JobRecord;
use App\Jobs\JobDispatcher;
use App\Jobs\JobDefinitionRegistry;
use App\Jobs\JobRepository;
use App\Jobs\JobState;
use App\Plugins\ExternalBroadcastPlugin;
use App\Stashes\DownloadPolicy;
use App\Stashes\StashId;
use App\Stashes\StashInputRepository;
use App\Stashes\StashRecord;
use App\Stashes\StashRepository;
use App\Support\PrefixedUlid;
use App\System\Storage\PathSanitizer;
use App\System\Activity\ActivityEventService;
use App\Vault\MediaItemRepository;
use Tempest\Http\Request;
use Tempest\Http\Responses\Json;
use Tempest\Http\Status;
use Tempest\Router\Delete;
use Tempest\Router\Get;
use Tempest\Router\Patch;
use Tempest\Router\Post;
use Tempest\Router\WithMiddleware;

use function Tempest\Support\str;

#[AllowApiClients]
#[WithMiddleware(RequireAuthMiddleware::class)]
final readonly class BroadcastController
{
    public function __construct(
        private StashRepository $stashes,
        private StashInputRepository $stashInputs,
        private BroadcastRepository $broadcasts,
        private BroadcastItemRepository $broadcastItems,
        private BroadcastLifecycleService $lifecycle,
        private BroadcastContextFactory $contexts,
        private MediaItemRepository $mediaItems,
        private BroadcastPathBuilder $paths,
        private JobRepository $jobs,
        private JobDispatcher $jobDispatcher,
        private JobDefinitionRegistry $jobDefinitions,
        private ActivityEventService $activity,
    ) {}

    #[Get('/api/v1/broadcast-plugins')]
    public function plugins(): Json
    {
        $plugins = [];

        foreach (BroadcastPluginRegistry::all() as $discovered) {
            foreach ($discovered->broadcastKeys as $key) {
                $plugins[] = $this->mapPlugin($key, $discovered);
            }
        }

        return new Json(['plugins' => $plugins]);
    }

    #[Get('/api/v1/stashes/{stashId}/broadcasts')]
    public function index(string $stashId): Json
    {
        $stash = $this->findStash($stashId);

        if ($stash === null) {
            return $this->notFound('Stash not found.');
        }

        $broadcasts = $this->broadcasts->listForStash(StashId::parse($stashId));
        $context = $broadcasts === [] ? null : $this->contexts->build($broadcasts[0]);

        return new Json([
            'broadcasts' => array_map(
                fn($broadcast): array => $this->mapBroadcast($broadcast, $context),
                $broadcasts,
            ),
        ]);
    }

    #[Post('/api/v1/stashes/{stashId}/broadcasts/preview')]
    public function preview(string $stashId, Request $request): Json
    {
        $stash = $this->findStash($stashId);

        if ($stash === null) {
            return $this->notFound('Stash not found.');
        }

        $body = ApiJson::normalizeRequest($request->body);
        $typeRaw = trim(ApiJson::string($body['type'] ?? null));

        if (! in_array($typeRaw, BroadcastPluginRegistry::broadcastKeys(), true)) {
            return new Json([
                'error' => [
                    'code' => 'validation_error',
                    'message' => 'Unsupported broadcast type.',
                ],
            ], Status::BAD_REQUEST);
        }

        $mediaKind = isset($body['mediaKind']) ? ApiJson::string($body['mediaKind']) : null;
        $preview = $this->lifecycle->preview(
            StashId::parse($stashId),
            $typeRaw,
            $mediaKind,
        );

        return new Json(['preview' => ApiJson::encode($preview->toArray())]);
    }

    #[Post('/api/v1/stashes/{stashId}/broadcasts')]
    public function create(string $stashId, Request $request): Json
    {
        $stash = $this->findStash($stashId);

        if ($stash === null) {
            return $this->notFound('Stash not found.');
        }

        $typedStashId = StashId::parse($stashId);

        $body = ApiJson::normalizeRequest($request->body);
        $typeRaw = trim(ApiJson::string($body['type'] ?? null));
        $name = trim(ApiJson::string($body['name'] ?? null));
        $slugRaw = trim(ApiJson::string($body['slug'] ?? null));

        if ($typeRaw === '') {
            return new Json([
                'error' => [
                    'code' => 'validation_error',
                    'message' => 'type is required.',
                ],
            ], Status::BAD_REQUEST);
        }

        // Validate against known plugin keys.
        $discoveredPlugin = BroadcastPluginRegistry::findByKey($typeRaw);

        if ($discoveredPlugin === null) {
            return new Json([
                'error' => [
                    'code' => 'validation_error',
                    'message' => 'Unsupported broadcast type.',
                ],
            ], Status::BAD_REQUEST);
        }

        // A name is a formality here, not something worth blocking on --
        // default to "{stash} {plugin label}"
        // and dedupe the slug automatically so adding a second broadcast of
        // the same type to a stash just works.
        $nameWasProvided = $name !== '';

        if (! $nameWasProvided) {
            $name = trim($stash->name . ' ' . $discoveredPlugin->name);
        }

        $slug = $slugRaw !== '' ? $slugRaw : str($name)->slug()->toString();

        if (! $nameWasProvided && $slugRaw === '') {
            $slug = $this->broadcasts->nextAvailableSlug($typedStashId, $slug);
        }

        try {
            $slug = PathSanitizer::sanitizeSegment($slug);
        } catch (\InvalidArgumentException) {
            return new Json([
                'error' => [
                    'code' => 'validation_error',
                    'message' => 'Invalid broadcast slug.',
                ],
            ], Status::BAD_REQUEST);
        }

        if ($this->broadcasts->findByStashAndSlug($typedStashId, $slug) !== null) {
            return new Json([
                'error' => [
                    'code' => 'validation_error',
                    'message' => 'Broadcast slug already exists for this stash.',
                ],
            ], Status::BAD_REQUEST);
        }

        $settings = is_array($body['settings'] ?? null) ? ApiJson::encode($body['settings']) : null;

        $destinationPath = $settings['destination_path'] ?? null;

        if (is_string($destinationPath)) {
            try {
                $this->paths->validateDestinationOverride($destinationPath);
            } catch (\InvalidArgumentException $exception) {
                return $this->validationError($exception->getMessage());
            }
        }

        $broadcast = $this->broadcasts->create(
            stashId: $typedStashId,
            type: $typeRaw,
            name: $name,
            slug: $slug,
            settings: $settings,
        );
        $this->activity->broadcastCreated($broadcast);

        $build = $this->jobDispatcher->dispatch(
            'core.broadcast',
            entityType: 'broadcast',
            entityId: (string) $broadcast->id,
            stashId: (string) $stash->id,
            payload: ['broadcast_id' => (string) $broadcast->id, 'action' => 'rebuild'],
            workload: 'background',
        );

        return new Json([
            'broadcast' => $this->mapBroadcast($broadcast),
            'build_job_id' => (string) $build->id,
            'policy_mismatch' => $this->policyMismatch($stash->downloadPolicy, $broadcast),
        ], Status::CREATED);
    }

    #[Get('/api/v1/broadcasts/{id}')]
    public function show(string $id): Json
    {
        $broadcast = $this->findBroadcast($id);

        if ($broadcast === null) {
            return $this->notFound('Broadcast not found.');
        }

        return new Json([
            'broadcast' => $this->mapBroadcast($broadcast),
        ]);
    }

    #[Get('/api/v1/broadcasts/{id}/items')]
    public function items(string $id): Json
    {
        $broadcast = $this->findBroadcast($id);

        if ($broadcast === null) {
            return $this->notFound('Broadcast not found.');
        }

        return new Json([
            'items' => $this->mapBroadcastItems($this->broadcastItems->listForBroadcast(BroadcastId::parse($id))),
        ]);
    }

    #[Post('/api/v1/broadcasts/{id}/rebuild')]
    public function rebuild(string $id): Json
    {
        $broadcast = $this->findBroadcast($id);

        if ($broadcast === null) {
            return $this->notFound('Broadcast not found.');
        }

        $active = $this->jobs->pendingOrProcessing(JobType::core('core.broadcast'), PrefixedUlid::parse((string) $broadcast->id));

        if ($active !== null) {
            return new Json([
                'operation' => [
                    'id' => (string) $active->id,
                    'state' => $active->state === JobState::Processing ? 'running' : 'accepted',
                ],
            ], Status::ACCEPTED);
        }

        $result = $this->jobDispatcher->dispatch(
            'core.broadcast',
            entityType: 'broadcast',
            entityId: (string) $broadcast->id,
            stashId: (string) $broadcast->stashId,
            payload: ['broadcast_id' => (string) $broadcast->id, 'action' => 'rebuild'],
            workload: 'background',
        );

        return new Json(ApiJson::encode([
            'operation' => [
                'id' => (string) $result->id,
                'state' => 'accepted',
            ],
        ]), Status::ACCEPTED);
    }

    #[Delete('/api/v1/broadcasts/{id}')]
    public function delete(string $id): Json
    {
        if ($this->findBroadcast($id) === null) {
            return $this->notFound('Broadcast not found.');
        }

        $job = $this->jobDispatcher->dispatch(
            'core.broadcast',
            entityType: 'broadcast',
            entityId: $id,
            payload: ['broadcast_id' => $id, 'action' => 'delete'],
            workload: 'background',
        );

        return new Json([
            'job_id' => (string) $job->id,
        ], Status::ACCEPTED);
    }

    #[Patch('/api/v1/broadcasts/{id}/source-settings')]
    public function updateSourceSettings(string $id, Request $request): Json
    {
        $broadcast = $this->findBroadcast($id);

        if ($broadcast === null) {
            return $this->notFound('Broadcast not found.');
        }

        $plugin = BroadcastPluginRegistry::findByKey($broadcast->type)?->plugin;

        if (! $plugin instanceof BroadcastPluginSourceOptions) {
            return $this->validationError('This Broadcast does not declare source-scoped options.');
        }

        $source = $request->body['source_reference'] ?? null;
        $values = $request->body['settings'] ?? null;

        if (! is_string($source) || $source === '' || ! is_array($values)) {
            return $this->validationError('source_reference and settings are required.');
        }

        $validSources = array_map(static fn($input): string => (string) $input->id, $this->stashInputs->listForStash($broadcast->stashId));

        if (! in_array($source, $validSources, true)) {
            return $this->validationError('Unknown source for this Broadcast.');
        }

        $controls = [];

        foreach ($plugin->sourceUiControls() as $control) {
            $controls[$control->name] = $control;
        }

        foreach ($values as $key => $value) {
            $control = is_string($key) ? $controls[$key] ?? null : null;

            if ($control === null || (! is_bool($value) && ! is_int($value) && ! is_float($value) && ! is_string($value))) {
                return $this->validationError('Invalid source-scoped option.');
            }

            if ($control->type === 'number' && ! is_int($value) && ! is_float($value)) {
                return $this->validationError('Invalid source-scoped option.');
            }
        }

        $settings = $this->decodeSettings($broadcast);
        $sourceSettings = is_array($settings['source_settings'] ?? null) ? $settings['source_settings'] : [];
        $sourceSettings[$source] = $values;
        $settings['source_settings'] = $sourceSettings;
        $broadcast->settings = $settings;
        $this->broadcasts->save($broadcast);
        $this->activity->broadcastUpdated($broadcast);

        return new Json(['broadcast' => $this->mapBroadcast($broadcast)]);
    }

    #[Patch('/api/v1/broadcasts/{id}/destination')]
    public function updateDestination(string $id, Request $request): Json
    {
        $broadcast = $this->findBroadcast($id);

        if ($broadcast === null) {
            return $this->notFound('Broadcast not found.');
        }

        // Read 'destination_path' from the raw body, not ApiJson::normalizeRequest()'s
        // output -- it's a single already-snake_case field, and there's no need to
        // risk the snake/camel transform on a filesystem path value.
        $rawDestination = $request->body['destination_path'] ?? null;
        $destinationPath = is_string($rawDestination) ? trim($rawDestination) : null;

        try {
            $validated = $this->paths->validateDestinationOverride($destinationPath);
        } catch (\InvalidArgumentException $exception) {
            return $this->validationError($exception->getMessage());
        }

        $settings = $this->decodeSettings($broadcast);

        if ($validated === null) {
            unset($settings['destination_path']);
        } else {
            $settings['destination_path'] = $validated;
        }

        $broadcast->settings = $settings === [] ? null : $settings;
        $this->broadcasts->save($broadcast);
        $this->activity->broadcastUpdated($broadcast);

        return new Json([
            'broadcast' => $this->mapBroadcast($broadcast),
        ]);
    }

    #[Post('/api/v1/broadcasts/{id}/actions')]
    public function invokeAction(string $id, Request $request): Json
    {
        $broadcast = $this->findBroadcast($id);

        if ($broadcast === null) {
            return $this->notFound('Broadcast not found.');
        }

        $body = ApiJson::normalizeRequest($request->body);
        $intent = is_string($body['intent'] ?? null) ? trim($body['intent']) : '';

        if ($intent === '') {
            return $this->validationError('intent is required.');
        }

        $type = $broadcast->type . '.broadcast.' . $intent;

        try {
            $definition = $this->jobDefinitions->get($type);
        } catch (\InvalidArgumentException) {
            return new Json(['error' => ['code' => 'unsupported_broadcast_action', 'message' => 'This broadcast action is not queueable.']], Status::BAD_REQUEST);
        }

        $job = $this->jobDispatcher->dispatch(
            $type,
            entityType: 'broadcast',
            entityId: (string) $broadcast->id,
            stashId: (string) $broadcast->stashId,
            payload: ['broadcast_id' => (string) $broadcast->id, 'operation' => $intent],
            workload: $definition->workload,
        );

        return new Json([
            'broadcast' => $this->mapBroadcast($broadcast),
            'operation' => ['id' => (string) $job->id, 'state' => 'accepted'],
        ], Status::ACCEPTED);
    }

    /** @return array<string, mixed> */
    private function decodeSettings(BroadcastRecord $broadcast): array
    {
        return $broadcast->settings ?? [];
    }

    private function validationError(string $message): Json
    {
        return new Json([
            'error' => [
                'code' => 'validation_error',
                'message' => $message,
            ],
        ], Status::BAD_REQUEST);
    }

    /** @return array<string, mixed>|null */
    private function policyMismatch(DownloadPolicy $policy, BroadcastRecord $broadcast): ?array
    {
        $type = $broadcast->type;
        $plugin = BroadcastPluginRegistry::findByKey($type)?->plugin;
        $satisfied = $plugin instanceof BroadcastPluginPolicy
            ? $plugin->acceptsDownloadPolicy($broadcast, $policy)
            : $policy !== DownloadPolicy::MetadataOnly;

        if ($satisfied) {
            return null;
        }

        return [
            'download_policy' => $policy->value,
            'broadcast_type' => $type,
            'message' => "This stash's \"{$policy->value}\" download policy won't produce media for a \"{$type}\" broadcast.",
            'compatible_download_policies' => array_values(array_map(
                fn(DownloadPolicy $candidate): string => $candidate->value,
                array_filter(
                    DownloadPolicy::cases(),
                    fn(DownloadPolicy $candidate): bool => $plugin instanceof BroadcastPluginPolicy
                        ? $plugin->acceptsDownloadPolicy($broadcast, $candidate)
                        : $candidate !== DownloadPolicy::MetadataOnly,
                ),
            )),
        ];
    }

    private function findStash(string $id): ?StashRecord
    {
        return StashId::isValid($id) ? $this->stashes->find(StashId::parse($id)) : null;
    }

    private function findBroadcast(string $id): ?BroadcastRecord
    {
        return BroadcastId::isValid($id) ? $this->broadcasts->find(BroadcastId::parse($id)) : null;
    }

    private function notFound(string $message): Json
    {
        return new Json([
            'error' => [
                'code' => 'not_found',
                'message' => $message,
            ],
        ], Status::NOT_FOUND);
    }

    /** @return array<string, mixed> */
    private function mapBroadcast(BroadcastRecord $broadcast, ?BroadcastContext $context = null): array
    {
        $broadcastId = BroadcastId::fromPrimaryKey($broadcast->id);

        $extra = [
            'items' => $this->mapBroadcastItems($this->broadcastItems->listForBroadcast($broadcastId)),
            'impact' => ApiJson::encode($this->lifecycle->impactFor($broadcast, $context)->toArray()),
        ];

        $plugin = BroadcastPluginRegistry::findByKey($broadcast->type)?->plugin;
        $metadata = $plugin instanceof BroadcastPluginPresentation
            ? $plugin->detailFields($broadcast)
            : [];
        $actions = $plugin instanceof BroadcastPluginPresentation
            ? $plugin->actions($broadcast)
            : [];
        $publishedUrl = null;

        foreach ($metadata as $field) {
            if (($field['kind'] ?? null) === 'url' && is_string($field['value'] ?? null)) {
                $publishedUrl = $field['value'];

                break;
            }
        }

        return [
            ...BroadcastResource::fromRecord($broadcast, $publishedUrl)->toArray(),
            ...$extra,
            'plugin_detail_fields' => $metadata,
            'plugin_actions' => $actions,
            'plugin_source_options' => $plugin instanceof BroadcastPluginSourceOptions
                ? array_map(
                    $this->mapControl(...),
                    $plugin->sourceUiControls(),
                )
                : [],
            'rebuild_operation' => $this->operation($this->jobs->latestForEntity(JobType::core('core.broadcast'), 'broadcast', (string) $broadcast->id)),
        ];
    }

    /** @return array{id: string, state: string}|null */
    private function operation(?JobRecord $job): ?array
    {
        return $job === null ? null : [
            'id' => (string) $job->id,
            'state' => $job->state->value,
        ];
    }

    /**
     * @param  list<BroadcastItemRecord>  $items
     * @return list<array<string, mixed>>
     */
    private function mapBroadcastItems(array $items): array
    {
        $mediaItemsById = $this->mediaItems->listByIds(array_values(array_unique(array_map(
            static fn(BroadcastItemRecord $item): string => (string) $item->mediaItemId,
            $items,
        ))));

        return array_map(
            static fn(BroadcastItemRecord $item): array => BroadcastItemResource::fromRecord(
                $item,
                $mediaItemsById[(string) $item->mediaItemId] ?? null,
            )->toArray(),
            $items,
        );
    }

    /** @return array<string, mixed> */
    private function mapPlugin(string $key, DiscoveredPlugin $discovered): array
    {
        return ApiJson::encode([
            'key' => $key,
            'label' => $discovered->name,
            'description' => $discovered->description,
            'supportedFileKinds' => array_map(
                static fn(FileKind $kind): string => $kind->value,
                $discovered->plugin->supportedFileKinds(),
            ),
            'uiControls' => array_map(
                $this->mapControl(...),
                $discovered->plugin->uiControls(),
            ),
            'sourceOptions' => $discovered->plugin instanceof BroadcastPluginSourceOptions
                ? array_map(
                    $this->mapControl(...),
                    $discovered->plugin->sourceUiControls(),
                )
                : [],
            'connectionSettingKey' => $discovered->plugin instanceof ExternalBroadcastPlugin ? $discovered->plugin->connectionSettingKey() : null,
            'librarySettingKey' => $discovered->plugin instanceof ExternalBroadcastPlugin ? $discovered->plugin->librarySettingKey() : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function mapControl(UiControl $control): array
    {
        return [
            'name' => $control->name,
            'label' => $control->label,
            'type' => $control->type,
            'default' => $control->default,
            'options' => $control->options,
            'description' => $control->description,
            'required' => $control->required,
        ];
    }
}
