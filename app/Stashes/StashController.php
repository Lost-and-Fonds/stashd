<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Auth\AuthContext;
use App\Commands\CommandDispatchService;
use App\Commands\CommandRecord;
use App\Commands\CommandRepository;
use App\Commands\CommandType;
use App\Commands\InvalidCommandPayload;
use App\Http\Api\ApiJson;
use App\Http\Middleware\RequireAuthMiddleware;
use App\Http\Routing\AllowApiClients;
use App\Jobs\JobIntent;
use App\Jobs\JobRepository;
use App\Jobs\JobState;
use App\Stashes\Api\StashInputResource;
use App\Stashes\Api\StashItemResource;
use App\Stashes\Api\StashResource;
use App\Support\Http\QueryPagination;
use App\Support\PrefixedUlid;
use App\System\Activity\ActivityEventService;
use App\Vault\AssetRepository;
use App\Vault\MediaItemState;
use Tempest\Database\Direction;
use Tempest\DateTime\DateTime;
use Tempest\Http\Request;
use Tempest\Http\Responses\Json;
use Tempest\Http\Status;
use Tempest\Router\Delete;
use Tempest\Router\Get;
use Tempest\Router\Patch;
use Tempest\Router\Post;
use Tempest\Router\WithMiddleware;

#[AllowApiClients]
#[WithMiddleware(RequireAuthMiddleware::class)]
final readonly class StashController
{
    public function __construct(
        private StashRepository $stashes,
        private StashItemRepository $stashItems,
        private StashInputRepository $stashInputs,
        private UpdateStashInputOptions $inputOptions,
        private CommandDispatchService $dispatch,
        private CommandRepository $commands,
        private AuthContext $context,
        private ActivityEventService $activity,
        private AssetRepository $assets,
        private JobRepository $jobs,
        private CreateStashWithInitialInput $initialInput,
    ) {}

    #[Get('/api/v1/stashes')]
    public function index(): Json
    {
        return new Json([
            'stashes' => array_map(
                function ($stash): array {
                    $items = $this->stashItems->listForStash(StashId::fromPrimaryKey($stash->id));
                    $sizes = $this->assets->totalSizeBytesByMediaItem(array_map(static fn($item): string => (string) $item->mediaItemId, $items));

                    return StashResource::fromRecord($stash, [
                        'itemCount' => count($items),
                        'storageBytes' => array_sum($sizes),
                        'inputSummary' => array_values(array_unique(array_map(fn($input): string => $input->providerKey, $this->stashInputs->listForStash(StashId::fromPrimaryKey($stash->id))))),
                        'lastDiscoveryAt' => $this->latestDiscoveryAt(StashId::fromPrimaryKey($stash->id)),
                    ])->toArray();
                },
                $this->stashes->list(),
            ),
        ]);
    }

    #[Post('/api/v1/stashes')]
    public function create(Request $request): Json
    {
        $body = ApiJson::normalizeRequest($request->body);

        $name = trim(ApiJson::string($body['name'] ?? null));

        if ($name === '') {
            $name = 'New Stash';
        }

        $syncMode = SyncMode::Automatic;

        if (isset($body['syncMode'])) {
            $syncMode = SyncMode::tryFrom(ApiJson::string($body['syncMode']));

            if ($syncMode === null) {
                return $this->validationError('Unsupported sync_mode.');
            }
        }

        $downloadPolicy = DownloadPolicy::Video;

        if (isset($body['downloadPolicy'])) {
            $downloadPolicy = DownloadPolicy::tryFrom(ApiJson::string($body['downloadPolicy']));

            if ($downloadPolicy === null) {
                return $this->validationError('Unsupported download_policy.');
            }
        }

        $organizationMode = OrganizationMode::Flat;

        if (isset($body['organizationMode'])) {
            $organizationMode = OrganizationMode::tryFrom(ApiJson::string($body['organizationMode']));

            if ($organizationMode === null) {
                return $this->validationError('Unsupported organization_mode.');
            }
        }

        $stash = $this->stashes->create(
            name: $name,
            syncMode: $syncMode,
            downloadPolicy: $downloadPolicy,
            organizationMode: $organizationMode,
            description: isset($body['description']) ? trim(ApiJson::string($body['description'])) : null,
        );

        $this->activity->stashCreated($stash);

        return new Json([
            'stash' => StashResource::fromRecord($stash)->toArray(),
        ], Status::CREATED);
    }

    #[Post('/api/v1/stashes/with-input')]
    public function createWithInput(Request $request): Json
    {
        $body = $request->body;
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $input = $request->body['input'] ?? null;

        if ($name === '' || ! is_array($input)) {
            return $this->validationError($name === '' ? 'name cannot be blank.' : 'input must be an object.');
        }

        $syncMode = SyncMode::tryFrom(is_string($body['syncMode'] ?? null) ? $body['syncMode'] : SyncMode::Automatic->value);
        $downloadPolicy = DownloadPolicy::tryFrom(is_string($body['downloadPolicy'] ?? null) ? $body['downloadPolicy'] : DownloadPolicy::Video->value);
        $organizationMode = OrganizationMode::tryFrom(is_string($body['organizationMode'] ?? null) ? $body['organizationMode'] : OrganizationMode::Flat->value);
        $plugin = is_string($input['plugin'] ?? null) ? $input['plugin'] : '';
        $source = is_array($input['source'] ?? null) ? self::object($input['source']) : null;
        $options = is_array($input['options'] ?? null) ? self::object($input['options']) : [];
        $resolvedInput = is_array($input['resolved_input'] ?? null) ? self::object($input['resolved_input']) : null;

        if ($syncMode === null || $downloadPolicy === null || $organizationMode === null || $plugin === '' || $source === null) {
            return $this->validationError('Invalid stash or input settings.');
        }

        if ($resolvedInput !== null) {
            $providerInputId = trim(ApiJson::string($resolvedInput['provider_input_id'] ?? null));
            $sourceUri = trim(ApiJson::string($resolvedInput['canonical_reference'] ?? null));
            $resolvedPlugin = trim(ApiJson::string($resolvedInput['plugin_key'] ?? null));

            if ($providerInputId === '' || $sourceUri === '' || $resolvedPlugin !== $plugin) {
                return $this->validationError('Invalid resolved input metadata.');
            }
        }

        // Discovery duration depends on the source and must not be cut off by
        // PHP's default 30-second request limit.
        try {
            $stash = $this->stashes->create(
                name: $name,
                syncMode: $syncMode,
                downloadPolicy: $downloadPolicy,
                organizationMode: $organizationMode,
                description: is_string($body['description'] ?? null) ? trim($body['description']) : null,
            );

            if ($resolvedInput !== null) {
                $this->stashInputs->create(
                    stashId: StashId::fromPrimaryKey($stash->id),
                    providerKey: $plugin,
                    inputType: StashInputTypeMapper::fromProviderInputType(ApiJson::string($resolvedInput['kind'] ?? null)),
                    sourceUri: $sourceUri,
                    providerInputId: $providerInputId,
                    title: ApiJson::string($resolvedInput['display_name'] ?? null) ?: ApiJson::string($resolvedInput['source_title'] ?? null) ?: null,
                    syncMode: $syncMode,
                    options: StashInputOptions::fromArray($options),
                );
                $sourceAvatar = ApiJson::string($resolvedInput['source_avatar_uri'] ?? null);

                if ($sourceAvatar !== '') {
                    $this->stashes->update($stash, iconUri: $sourceAvatar);
                }
            }
            $this->activity->stashCreated($stash);
            $this->dispatch->dispatch(CommandType::StashAddInput, [
                'stash_id' => (string) $stash->id,
                'plugin' => $plugin,
                'source' => $source,
                'options' => $options,
            ], $this->context->user());
        } catch (\InvalidArgumentException|\RuntimeException|InvalidCommandPayload $exception) {
            return $this->validationError($exception->getMessage());
        }

        return new Json(['stash' => StashResource::fromRecord($stash)->toArray()], Status::CREATED);
    }

    #[Get('/api/v1/stashes/{id}')]
    public function show(string $id): Json
    {
        $stash = $this->findStash($id);

        if ($stash === null) {
            return $this->notFound('Stash not found.');
        }

        return new Json([
            'stash' => StashResource::fromRecord($stash, [
                'lastDiscoveryAt' => $this->latestDiscoveryAt(StashId::fromPrimaryKey($stash->id)),
            ])->toArray(),
        ]);
    }

    private function latestDiscoveryAt(StashId $stashId): ?DateTime
    {
        $latest = null;

        foreach ($this->stashInputs->listForStash($stashId) as $input) {
            $candidate = $input->lastSuccessAt ?? $input->createdAt;

            if ($candidate !== null && ($latest === null || $candidate->toNativeDateTime() > $latest->toNativeDateTime())) {
                $latest = $candidate;
            }
        }

        return $latest;
    }

    #[Get('/api/v1/stashes/{id}/items')]
    public function items(string $id, Request $request): Json
    {
        $stash = $this->findStash($id);

        if ($stash === null) {
            return $this->notFound('Stash not found.');
        }

        $stashId = StashId::fromPrimaryKey($stash->id);
        [$limit, $offset] = QueryPagination::parse($request);

        $rawSearch = $request->get('search');
        $search = is_string($rawSearch) ? trim($rawSearch) : '';

        $rawStatus = $request->get('status');
        $status = is_string($rawStatus) ? MediaItemState::tryFrom($rawStatus) : null;

        $rawIncludeIgnored = $request->get('include_ignored');
        $includeIgnored = ! (is_string($rawIncludeIgnored) && $rawIncludeIgnored === 'false');

        $rawSort = $request->get('sort');
        $sort = is_string($rawSort) ? $rawSort : 'position';

        $rawDirection = $request->get('dir');
        $direction = is_string($rawDirection) && strtolower($rawDirection) === 'desc' ? Direction::DESC : Direction::ASC;

        $filters = [
            'search' => $search === '' ? null : $search,
            'status' => $status,
            'includeIgnored' => $includeIgnored,
        ];

        $stashItems = $this->stashItems->listForStash(
            $stashId,
            $limit,
            $offset,
            search: $filters['search'],
            status: $filters['status'],
            includeIgnored: $filters['includeIgnored'],
            sort: $sort,
            direction: $direction,
        );

        $mediaItemIds = array_values(array_unique(array_map(
            static fn($item): string => (string) $item->mediaItemId,
            $stashItems,
        )));

        $totalSizeByMediaItem = $this->assets->totalSizeBytesByMediaItem($mediaItemIds);
        $downloadFailureByMediaItem = $this->jobs->latestDownloadFailureByMediaItem($mediaItemIds);

        return new Json([
            'items' => array_map(
                static fn($item): array => StashItemResource::fromRecord(
                    $item,
                    $item->mediaItem,
                    $totalSizeByMediaItem[(string) $item->mediaItemId] ?? null,
                    $downloadFailureByMediaItem[(string) $item->mediaItemId] ?? null,
                )->toArray(),
                $stashItems,
            ),
            'total' => $this->stashItems->countForStash(
                $stashId,
                search: $filters['search'],
                status: $filters['status'],
                includeIgnored: $filters['includeIgnored'],
            ),
            'limit' => $limit,
            'offset' => $offset,
            'status_counts' => $this->stashItems->statusCountsForStash($stashId),
            'downloadable_count' => $this->stashItems->downloadableCountForStash($stashId),
            'ignored_count' => $this->stashItems->countIgnoredForStash($stashId),
            // Unfiltered, whole-stash count -- distinct from `total` (which
            // reflects the current search/status/includeIgnored filters) so
            // the UI can tell "this stash has no items at all" apart from
            // "the current filters match nothing".
            'stash_item_count' => $this->stashItems->countForStash($stashId),
        ]);
    }

    #[Get('/api/v1/stashes/{id}/inputs')]
    public function inputs(string $id): Json
    {
        $stash = $this->findStash($id);

        if ($stash === null) {
            return $this->notFound('Stash not found.');
        }

        return new Json([
            'inputs' => array_map(
                fn($input): array => [
                    ...StashInputResource::fromRecord($input, $this->inputOptions->declaredOptions($input))->toArray(),
                    'sync_operation' => $this->operation($this->commands->latestForTarget(CommandType::StashSyncInput, 'stash_input', (string) $input->id)),
                ],
                $this->stashInputs->listForStash(StashId::fromPrimaryKey($stash->id)),
            ),
        ]);
    }

    #[Post('/api/v1/stashes/{id}/inputs')]
    public function addInput(string $id, Request $request): Json
    {
        $existing = $this->findStash($id);

        if ($existing === null) {
            return $this->notFound('Stash not found.');
        }

        $body = ApiJson::normalizeRequest($request->body);

        if (is_string($request->body['plugin'] ?? null) && is_array($request->body['source'] ?? null)) {
            try {
                $result = $this->initialInput->addToExisting(
                    $existing,
                    $request->body['plugin'],
                    array_filter($request->body['source'], is_string(...), ARRAY_FILTER_USE_KEY),
                    is_array($request->body['options'] ?? null) ? self::object($request->body['options']) : [],
                );
            } catch (\InvalidArgumentException|\RuntimeException $exception) {
                return $this->validationError($exception->getMessage());
            }

            return new Json(ApiJson::encode($result->toArray()), Status::CREATED);
        }

        $options = [
            'stash_id' => $id,
            'preflight_command_id' => trim(ApiJson::string($body['preflightCommandId'] ?? null)),
            // Sourced from the raw, un-normalized body: provider-option keys (e.g.
            // provider-declared keys are opaque identifiers, not DTO field names, so
            // ApiJson's snake/camel key transform must not touch them.
            'options' => is_array($request->body['options'] ?? null) ? $request->body['options'] : [],
        ];

        try {
            $result = $this->dispatch->dispatch(
                CommandType::StashAddInput,
                $options,
                $this->context->user(),
            );
        } catch (InvalidCommandPayload $exception) {
            return $this->validationError($exception->getMessage());
        }

        return new Json(ApiJson::encode($result->toArray()), Status::CREATED);
    }

    /** Checks every input of the stash for new items, on demand. */
    #[Post('/api/v1/stashes/{id}/sync')]
    public function sync(string $id): Json
    {
        $stash = $this->findStash($id);

        if ($stash === null) {
            return $this->notFound('Stash not found.');
        }

        $commandIds = [];

        foreach ($this->stashInputs->listForStash(StashId::fromPrimaryKey($stash->id)) as $input) {
            try {
                $result = $this->dispatch->dispatch(
                    CommandType::StashSyncInput,
                    ['stash_input_id' => (string) $input->id],
                    $this->context->user(),
                );
            } catch (InvalidCommandPayload $exception) {
                return $this->validationError($exception->getMessage());
            }

            $commandIds[] = $result->toArray()['command_id'] ?? null;
        }

        return new Json(ApiJson::encode([
            'command_ids' => array_values(array_filter($commandIds, is_string(...))),
        ]), Status::ACCEPTED);
    }

    #[Post('/api/v1/stashes/{id}/inputs/{inputId}/sync')]
    public function syncInput(string $id, string $inputId): Json
    {
        $stash = $this->findStash($id);

        if ($stash === null) {
            return $this->notFound('Stash not found.');
        }

        $input = $this->findStashInput($stash, $inputId);

        if ($input === null) {
            return $this->notFound('Stash input not found.');
        }

        $active = $this->jobs->pendingOrProcessing(JobIntent::SyncInput, PrefixedUlid::parse($inputId));

        if ($active?->commandId !== null) {
            return new Json([
                'operation' => [
                    'id' => (string) $active->commandId,
                    'state' => $active->state === JobState::Processing ? 'running' : 'accepted',
                ],
            ], Status::ACCEPTED);
        }

        try {
            $result = $this->dispatch->dispatch(
                CommandType::StashSyncInput,
                ['stash_input_id' => (string) $input->id],
                $this->context->user(),
            );
        } catch (InvalidCommandPayload $exception) {
            return $this->validationError($exception->getMessage());
        }

        return new Json(ApiJson::encode([
            'operation' => [
                'id' => (string) $result->command->id,
                'state' => $result->command->state->value,
            ],
        ]), Status::ACCEPTED);
    }

    #[Patch('/api/v1/stashes/{id}/inputs/{inputId}')]
    public function updateInput(string $id, string $inputId, Request $request): Json
    {
        $stash = $this->findStash($id);

        if ($stash === null) {
            return $this->notFound('Stash not found.');
        }

        $input = $this->findStashInput($stash, $inputId);

        if ($input === null) {
            return $this->notFound('Stash input not found.');
        }

        // Sourced from the raw, un-normalized body: provider-option keys are
        // opaque identifiers, not DTO field names (see addInput() above).
        $rawOptionsBody = $request->body['options'] ?? null;
        $rawOptions = is_array($rawOptionsBody) ? array_filter($rawOptionsBody, is_string(...), ARRAY_FILTER_USE_KEY) : [];
        $inputOptions = StashInputOptions::fromArray($rawOptions);

        foreach ([$inputOptions?->titleRegexInclude, $inputOptions?->titleRegexExclude] as $pattern) {
            if ($pattern !== null && ! StashInputOptions::isValidTitleRegex($pattern)) {
                return $this->validationError("Invalid title filter pattern: {$pattern}");
            }
        }

        $input = $this->inputOptions->execute($stash, $input, $inputOptions);
        $this->activity->inputUpdated($input);

        return new Json([
            'input' => StashInputResource::fromRecord($input, $this->inputOptions->declaredOptions($input))->toArray(),
        ]);
    }

    #[Patch('/api/v1/stashes/{id}')]
    public function update(string $id, Request $request): Json
    {
        $stash = $this->findStash($id);

        if ($stash === null) {
            return $this->notFound('Stash not found.');
        }

        $body = ApiJson::normalizeRequest($request->body);

        $name = null;

        if (isset($body['name'])) {
            $name = trim(ApiJson::string($body['name']));

            if ($name === '') {
                return $this->validationError('name cannot be blank.');
            }
        }

        $syncMode = null;

        if (isset($body['syncMode'])) {
            $syncMode = SyncMode::tryFrom(ApiJson::string($body['syncMode']));

            if ($syncMode === null) {
                return $this->validationError('Unsupported sync_mode.');
            }
        }

        $downloadPolicy = null;

        if (isset($body['downloadPolicy'])) {
            $downloadPolicy = DownloadPolicy::tryFrom(ApiJson::string($body['downloadPolicy']));

            if ($downloadPolicy === null) {
                return $this->validationError('Unsupported download_policy.');
            }
        }

        $organizationMode = null;

        if (isset($body['organizationMode'])) {
            $organizationMode = OrganizationMode::tryFrom(ApiJson::string($body['organizationMode']));

            if ($organizationMode === null) {
                return $this->validationError('Unsupported organization_mode.');
            }
        }

        $stash = $this->stashes->update(
            $stash,
            name: $name,
            description: isset($body['description']) ? trim(ApiJson::string($body['description'])) : null,
            syncMode: $syncMode,
            downloadPolicy: $downloadPolicy,
            organizationMode: $organizationMode,
        );
        $this->activity->stashUpdated($stash);

        return new Json([
            'stash' => StashResource::fromRecord($stash)->toArray(),
        ]);
    }

    #[Delete('/api/v1/stashes/{id}')]
    public function delete(string $id): Json
    {
        $stash = $this->findStash($id);

        if ($stash === null) {
            return $this->notFound('Stash not found.');
        }

        try {
            $this->stashes->delete($stash);
        } catch (StashDeleteFailed) {
            return new Json([
                'error' => [
                    'code' => 'delete_failed',
                    'message' => 'Could not delete this stash right now. Please try again.',
                ],
            ], Status::CONFLICT);
        }
        $this->activity->stashDeleted((string) $stash->id);

        return new Json(['deleted' => true]);
    }

    #[Get('/api/v1/stashes/{id}/delete-impact')]
    public function deleteImpact(string $id): Json
    {
        $stash = $this->findStash($id);

        if ($stash === null) {
            return $this->notFound('Stash not found.');
        }

        return new Json([
            'delete_impact' => ApiJson::encode($this->stashes->deleteImpact($stash)),
        ]);
    }

    private function findStash(string $id): ?StashRecord
    {
        return StashId::isValid($id) ? $this->stashes->find(StashId::parse($id)) : null;
    }

    private function findStashInput(StashRecord $stash, string $inputId): ?StashInputRecord
    {
        if (! StashInputId::isValid($inputId)) {
            return null;
        }

        $input = $this->stashInputs->find(StashInputId::parse($inputId));

        if ($input === null || $input->stashId->toString() !== StashId::fromPrimaryKey($stash->id)->toString()) {
            return null;
        }

        return $input;
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

    private function validationError(string $message): Json
    {
        return new Json([
            'error' => [
                'code' => 'validation_error',
                'message' => $message,
            ],
        ], Status::BAD_REQUEST);
    }

    /** @return array{id: string, state: string}|null */
    private function operation(?CommandRecord $command): ?array
    {
        return $command === null ? null : [
            'id' => (string) $command->id,
            'state' => $command->state->value,
        ];
    }

    /** @param array<mixed, mixed> $value
     * @return array<string, mixed>
     */
    private static function object(array $value): array
    {
        return array_filter($value, is_string(...), ARRAY_FILTER_USE_KEY);
    }
}
