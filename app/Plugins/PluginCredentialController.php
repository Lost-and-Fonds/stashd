<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Http\Middleware\RequireAuthMiddleware;
use App\Http\Routing\AllowApiClients;
use Tempest\Http\Request;
use Tempest\Http\Responses\Json;
use Tempest\Http\Status;
use Tempest\Router\Get;
use Tempest\Router\Put;
use Tempest\Router\WithMiddleware;

#[AllowApiClients]
#[WithMiddleware(RequireAuthMiddleware::class)]
final readonly class PluginCredentialController
{
    public function __construct(private PluginCredentialService $credentials) {}

    #[Get('/api/v1/plugin-credentials')]
    public function index(): Json
    {
        return new Json(['plugins' => $this->credentials->list()]);
    }

    #[Put('/api/v1/plugin-credentials/{pluginKey}/{credentialKey}')]
    public function replace(string $pluginKey, string $credentialKey, Request $request): Json
    {
        $value = $request->body['value'] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return $this->validationError('value is required.');
        }

        $credential = $this->credentials->replace($pluginKey, $credentialKey, $value);

        if ($credential === null) {
            return new Json(['error' => ['code' => 'not_found', 'message' => 'Plugin credential not found.']], Status::NOT_FOUND);
        }

        return new Json(['credential' => $credential->toArray(true)]);
    }

    private function validationError(string $message): Json
    {
        return new Json(['error' => ['code' => 'validation_error', 'message' => $message]], Status::BAD_REQUEST);
    }
}
