<?php

declare(strict_types=1);

namespace App\Connections;

use App\Http\Api\ApiJson;
use App\Http\Middleware\RequireAuthMiddleware;
use App\Http\Routing\AllowApiClients;
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
final readonly class ConnectionController
{
    public function __construct(
        private ConnectionRepository $connections,
        private PluginConnectionService $service,
        private ExternalBroadcastPluginRegistry $plugins,
    ) {}

    #[Get('/api/v1/connections')]
    public function index(): Json
    {
        return new Json([
            'connections' => array_map(
                static fn($connection): array => ConnectionResource::fromRecord($connection)->toArray(),
                $this->connections->listAll(),
            ),
        ]);
    }

    #[Post('/api/v1/connections')]
    public function create(Request $request): Json
    {
        $body = $this->requestBody($request);
        $typeRaw = trim($this->stringValue($body['plugin_key'] ?? $body['pluginKey'] ?? $body['type'] ?? null));
        $name = trim($this->stringValue($body['name'] ?? null));
        $endpoint = trim($this->stringValue($body['endpoint'] ?? $body['base_uri'] ?? $body['baseUri'] ?? null));

        if ($typeRaw === '' || $name === '' || $endpoint === '') {
            return $this->validationError('plugin_key, name, and endpoint are required.');
        }

        if ($this->plugins->findByLogicalKey($typeRaw) === null) {
            return $this->validationError('Unsupported plugin key.');
        }

        $token = isset($body['token']) ? $this->stringValue($body['token']) : null;
        $settings = is_array($body['settings'] ?? null) ? ApiJson::encode($body['settings']) : null;

        $connection = $this->service->create(
            pluginKey: $typeRaw,
            name: $name,
            endpoint: $endpoint,
            token: $token,
            settings: $settings,
        );

        return new Json([
            'connection' => ConnectionResource::fromRecord($connection)->toArray(),
        ], Status::CREATED);
    }

    #[Get('/api/v1/connections/{id}')]
    public function show(string $id): Json
    {
        $connection = $this->connections->find(PrefixedUlid::parse($id));

        if ($connection === null) {
            return $this->notFound('Connection not found.');
        }

        return new Json([
            'connection' => ConnectionResource::fromRecord($connection)->toArray(),
        ]);
    }

    #[Patch('/api/v1/connections/{id}')]
    public function update(string $id, Request $request): Json
    {
        $body = $this->requestBody($request);

        try {
            $connection = $this->service->update(
                id: PrefixedUlid::parse($id),
                name: isset($body['name']) ? trim($this->stringValue($body['name'])) : null,
                endpoint: isset($body['endpoint']) || isset($body['base_uri']) || isset($body['baseUri'])
                    ? trim($this->stringValue($body['endpoint'] ?? $body['base_uri'] ?? $body['baseUri']))
                    : null,
                settings: is_array($body['settings'] ?? null) ? ApiJson::encode($body['settings']) : null,
                token: isset($body['token']) ? $this->stringValue($body['token']) : null,
            );
        } catch (ConnectionException $exception) {
            if ($exception->errorCode === 'connection_not_found') {
                return $this->notFound('Connection not found.');
            }

            throw $exception;
        }

        return new Json([
            'connection' => ConnectionResource::fromRecord($connection)->toArray(),
        ]);
    }

    #[Delete('/api/v1/connections/{id}')]
    public function delete(string $id): Json
    {
        $connection = $this->connections->find(PrefixedUlid::parse($id));

        if ($connection === null) {
            return $this->notFound('Connection not found.');
        }

        $this->connections->delete($connection);

        return new Json(['deleted' => true]);
    }

    #[Post('/api/v1/connections/{id}/operations/{operation}')]
    public function operation(string $id, string $operation, Request $request): Json
    {
        try {
            $body = $this->requestBody($request);
            $payload = [];

            foreach (is_array($body['payload'] ?? null) ? $body['payload'] : [] as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $payload[$key] = $value;
                }
            }
            $result = $this->service->invokeOperation(PrefixedUlid::parse($id), $operation, $payload);
        } catch (ConnectionException $exception) {
            if ($exception->errorCode === 'connection_not_found') {
                return $this->notFound('Connection not found.');
            }

            return new Json([
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ],
            ], Status::BAD_REQUEST);
        }

        return new Json(ApiJson::encode($this->normalizeOperationResult($result)));
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

    /** @return array<string, mixed> */
    private function requestBody(Request $request): array
    {
        $body = $request->body;
        /** @var array<string, mixed> $filtered */
        $filtered = [];

        foreach ($body as $key => $value) {
            if (is_string($key)) {
                $filtered[$key] = $value;
            }
        }

        $normalized = ApiJson::normalizeRequest($filtered);
        /** @var array<string, mixed> $result */
        $result = [];

        foreach ($normalized as $key => $value) {
            $result[$key] = $value;
        }

        return $result;
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** @param array<string, mixed> $result
     *  @return array<string, mixed>
     */
    private function normalizeOperationResult(array $result): array
    {
        if (! is_array($result['values'] ?? null)) {
            return $result;
        }

        $result['values'] = array_values(array_filter(array_map(static function (mixed $value): ?array {
            if (! is_array($value) || ! is_string($value['key'] ?? null)) {
                return null;
            }

            $raw = $value['value'] ?? null;

            if (is_array($raw) && array_key_exists('value', $raw)) {
                $raw = $raw['value'];
            }

            return is_scalar($raw) ? ['key' => $value['key'], 'value' => $raw] : null;
        }, $result['values'])));

        return $result;
    }
}
