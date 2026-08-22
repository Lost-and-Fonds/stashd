<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Auth\AuthContext;
use App\Commands\CommandDispatchService;
use App\Commands\CommandId;
use App\Commands\CommandRepository;
use App\Commands\CommandType;
use App\Commands\InvalidCommandPayload;
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
final readonly class StashPreflightController
{
    public function __construct(
        private CommandDispatchService $dispatch,
        private CommandRepository $commands,
        private AuthContext $context,
    ) {}

    #[Post('/api/v1/stashes/preflight')]
    public function create(Request $request): Json
    {
        $body = ApiJson::normalizeRequest($request->body);
        $options = [
            'source_uri' => trim((string) ($body['sourceUri'] ?? '')),
            'source_title' => $body['sourceTitle'] ?? null,
            'origin' => is_string($body['origin'] ?? null)
                ? $body['origin']
                : PreflightOrigin::Api->value,
        ];

        if ($options['source_uri'] === '') {
            return new Json([
                'error' => [
                    'code' => 'validation_error',
                    'message' => 'source_uri is required.',
                ],
            ], Status::BAD_REQUEST);
        }

        try {
            $result = $this->dispatch->dispatch(
                CommandType::StashPreflight,
                $options,
                $this->context->user(),
            );
        } catch (InvalidCommandPayload $exception) {
            return new Json([
                'error' => [
                    'code' => 'validation_error',
                    'message' => $exception->getMessage(),
                ],
            ], Status::BAD_REQUEST);
        }

        return new Json(ApiJson::encode($result->toArray()), Status::CREATED);
    }

    #[Get('/api/v1/stashes/preflight/{commandId}/review')]
    public function review(string $commandId): Json
    {
        $command = CommandId::isValid($commandId) ? $this->commands->find(CommandId::parse($commandId)) : null;

        if ($command === null || $command->type !== CommandType::StashPreflight) {
            return new Json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Preflight command not found.',
                ],
            ], Status::NOT_FOUND);
        }

        $preflight = $command->result;

        $reviewUrl = is_array($preflight) ? ($preflight['review_url'] ?? null) : null;

        return new Json([
            'command_id' => (string) $command->id,
            'state' => $command->state->value,
            'review_url' => $reviewUrl,
            'preflight' => is_array($preflight) ? ApiJson::encode($preflight) : null,
            'ui_note' => 'Review UI placeholder — wire Glance dashboard in Phase 6.',
        ]);
    }
}
