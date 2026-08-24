<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Http\Api\ApiJson;
use App\Http\Middleware\RequireAuthMiddleware;
use App\Http\Routing\AllowApiClients;
use Tempest\Http\Request;
use Tempest\Http\Responses\Json;
use Tempest\Http\Status;
use Tempest\Router\Get;
use Tempest\Router\Post;
use Tempest\Router\WithMiddleware;

#[AllowApiClients]
#[WithMiddleware(RequireAuthMiddleware::class)]
final readonly class InputPluginController
{
    public function __construct(private ExternalInputPluginRegistry $plugins) {}

    #[Get('/api/v1/input-plugins')]
    public function index(): Json
    {
        return new Json(['plugins' => array_map(static fn(PluginInputDefinition $plugin): array => [
            'key' => $plugin->id,
            'label' => $plugin->name,
            'source_fields' => array_map(static fn(PluginSourceField $field): array => $field->toArray(), $plugin->sourceFields),
        ], $this->plugins->definitions())]);
    }

    #[Post('/api/v1/input-plugins/{id}/preflight')]
    public function preflight(string $id, Request $request): Json
    {
        $plugin = $this->plugins->definition($id);

        if ($plugin === null) {
            return new Json(['error' => ['code' => 'not_found', 'message' => 'Input plugin not found.']], Status::NOT_FOUND);
        }

        $source = $request->body['source'] ?? null;

        if (! is_array($source)) {
            return $this->validationError('source must be an object.');
        }

        $source = array_filter($source, is_string(...), ARRAY_FILTER_USE_KEY);

        try {
            $resolved = $this->plugins->resolveSource($id, $plugin->normalizeSource($source));
        } catch (\InvalidArgumentException $exception) {
            return $this->validationError($exception->getMessage());
        } catch (\Throwable $exception) {
            return new Json(['error' => ['code' => 'invalid_source', 'message' => $exception->getMessage()]], Status::BAD_REQUEST);
        }

        return new Json(ApiJson::encode([
            'valid' => true,
            'resolved_source' => [
                'plugin_key' => $resolved->providerKey,
                'canonical_reference' => $resolved->sourceUri->toString(),
                'provider_input_id' => $resolved->providerInputId,
                'kind' => $resolved->inputType,
                'display_name' => $resolved->title,
            ],
        ]));
    }

    private function validationError(string $message): Json
    {
        return new Json(['error' => ['code' => 'validation_error', 'message' => $message]], Status::BAD_REQUEST);
    }
}
