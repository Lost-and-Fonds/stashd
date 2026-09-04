<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Http\Api\ApiJson;
use App\Http\Middleware\RequireAuthMiddleware;
use App\Http\Routing\AllowApiClients;
use Tempest\Http\Request;
use Tempest\Http\Responses\Json;
use Tempest\Http\Status;
use Tempest\Router\Post;
use Tempest\Router\WithMiddleware;

#[AllowApiClients]
#[WithMiddleware(RequireAuthMiddleware::class)]
final readonly class StashPreflightController
{
    public function __construct(private DiscoverStashInput $discover) {}

    #[Post('/api/v1/stashes/preflight')]
    public function create(Request $request): Json
    {
        $body = ApiJson::normalizeRequest($request->body);
        $sourceUri = trim(ApiJson::string($body['sourceUri'] ?? $body['source_uri'] ?? null));

        if ($sourceUri === '') {
            return new Json(['error' => ['code' => 'validation_error', 'message' => 'source_uri is required.']], Status::BAD_REQUEST);
        }

        try {
            $result = $this->discover->execute([
                'source_uri' => $sourceUri,
                'source_title' => $body['sourceTitle'] ?? $body['source_title'] ?? null,
                'provider_options' => is_array($body['providerOptions'] ?? $body['provider_options'] ?? null) ? ($body['providerOptions'] ?? $body['provider_options']) : [],
            ]);
        } catch (\Throwable $exception) {
            return new Json(['error' => ['code' => 'invalid_source', 'message' => $exception->getMessage()]], Status::BAD_REQUEST);
        }

        return new Json(['preflight' => $result->toArray()]);
    }
}
