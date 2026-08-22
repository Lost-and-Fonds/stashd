<?php

declare(strict_types=1);

namespace App\MediaServers;

use App\Http\Api\ApiJson;
use App\Http\Middleware\RequireAuthMiddleware;
use App\Http\Routing\AllowApiClients;
use App\MediaServers\Api\MediaServerResource;
use App\Plugins\ExternalBroadcastPluginRegistry;
use App\Support\PrefixedUlid;
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
final readonly class MediaServerController
{
    public function __construct(
        private MediaServerConnectionRepository $connections,
        private MediaServerConnectionService $service,
        private ExternalBroadcastPluginRegistry $plugins,
    ) {
    }

    #[Get('/api/v1/media-servers')]
    public function index(): Json
    {
        return new Json([
            'media_servers' => array_map(
                static fn ($connection): array => MediaServerResource::fromRecord($connection)->toArray(),
                $this->connections->listAll(),
            ),
        ]);
    }

    #[Post('/api/v1/media-servers')]
    public function create(Request $request): Json
    {
        $body = ApiJson::normalizeRequest($request->body);
        $typeRaw = trim((string) ($body['type'] ?? ''));
        $name = trim((string) ($body['name'] ?? ''));
        $baseUri = trim((string) ($body['base_uri'] ?? $body['baseUri'] ?? ''));

        if ($typeRaw === '' || $name === '' || $baseUri === '') {
            return $this->validationError('type, name, and base_uri are required.');
        }

        if ($this->plugins->findByLogicalKey($typeRaw) === null) {
            return $this->validationError('Unsupported media server type.');
        }

        $token = isset($body['token']) ? (string) $body['token'] : null;
        $settings = is_array($body['settings'] ?? null) ? ApiJson::encode($body['settings']) : null;

        $connection = $this->service->create(
            type: $typeRaw,
            name: $name,
            baseUri: $baseUri,
            token: $token,
            settings: $settings,
        );

        return new Json([
            'media_server' => MediaServerResource::fromRecord($connection)->toArray(),
        ], Status::CREATED);
    }

    #[Get('/api/v1/media-servers/{id}')]
    public function show(string $id): Json
    {
        $connection = $this->connections->find(PrefixedUlid::parse($id));

        if ($connection === null) {
            return $this->notFound('Media server connection not found.');
        }

        return new Json([
            'media_server' => MediaServerResource::fromRecord($connection)->toArray(),
        ]);
    }

    #[Patch('/api/v1/media-servers/{id}')]
    public function update(string $id, Request $request): Json
    {
        $body = ApiJson::normalizeRequest($request->body);

        try {
            $connection = $this->service->update(
                id: PrefixedUlid::parse($id),
                name: isset($body['name']) ? trim((string) $body['name']) : null,
                baseUri: isset($body['base_uri']) || isset($body['baseUri'])
                    ? trim((string) ($body['base_uri'] ?? $body['baseUri']))
                    : null,
                settings: is_array($body['settings'] ?? null) ? ApiJson::encode($body['settings']) : null,
                token: isset($body['token']) ? (string) $body['token'] : null,
            );
        } catch (MediaServerException $exception) {
            if ($exception->errorCode === 'media_server_not_found') {
                return $this->notFound('Media server connection not found.');
            }

            throw $exception;
        }

        return new Json([
            'media_server' => MediaServerResource::fromRecord($connection)->toArray(),
        ]);
    }

    #[Delete('/api/v1/media-servers/{id}')]
    public function delete(string $id): Json
    {
        $connection = $this->connections->find(PrefixedUlid::parse($id));

        if ($connection === null) {
            return $this->notFound('Media server connection not found.');
        }

        $this->connections->delete($connection);

        return new Json(['deleted' => true]);
    }

    #[Post('/api/v1/connections/{id}/operations/{operation}')]
    public function operation(string $id, string $operation, Request $request): Json
    {
        try {
            $body = ApiJson::normalizeRequest($request->body);
            $payload = [];
            foreach (is_array($body['payload'] ?? null) ? $body['payload'] : [] as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $payload[$key] = $value;
                }
            }
            $result = $this->service->invokeOperation(PrefixedUlid::parse($id), $operation, $payload);
        } catch (MediaServerException $exception) {
            if ($exception->errorCode === 'media_server_not_found') {
                return $this->notFound('Media server connection not found.');
            }

            return new Json([
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ],
            ], Status::BAD_REQUEST);
        }

        return new Json(ApiJson::encode($result));
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
}
