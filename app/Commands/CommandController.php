<?php

declare(strict_types=1);

namespace App\Commands;

use App\Auth\AuthContext;
use App\Commands\Api\CommandResource;
use App\Http\Api\ApiJson;
use App\Http\Middleware\RequireAuthMiddleware;
use App\Http\Routing\AllowApiClients;
use App\Jobs\Api\JobResource;
use App\Jobs\JobRepository;
use Tempest\Http\Request;
use Tempest\Http\Responses\Json;
use Tempest\Http\Status;
use Tempest\Router\Get;
use Tempest\Router\Post;
use Tempest\Router\WithMiddleware;

#[AllowApiClients]
#[WithMiddleware(RequireAuthMiddleware::class)]
final readonly class CommandController
{
    public function __construct(
        private CommandDispatchService $dispatch,
        private CommandRepository $commands,
        private JobRepository $jobs,
        private AuthContext $context,
    ) {}

    #[Post('/api/v1/commands')]
    public function create(Request $request): Json
    {
        $body = ApiJson::normalizeRequest($request->body);
        $typeRaw = trim((string) ($body['type'] ?? ''));

        if ($typeRaw === '') {
            return new Json([
                'error' => [
                    'code' => 'validation_error',
                    'message' => 'type is required.',
                ],
            ], Status::BAD_REQUEST);
        }

        $type = CommandType::tryFrom($typeRaw);

        if ($type === null) {
            return new Json([
                'error' => [
                    'code' => 'validation_error',
                    'message' => 'Unsupported command type.',
                ],
            ], Status::BAD_REQUEST);
        }

        $options = is_array($body['options'] ?? null) ? $body['options'] : [];

        try {
            $result = $this->dispatch->dispatch($type, $options, $this->context->user());
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

    #[Get('/api/v1/commands/{id}')]
    public function show(string $id): Json
    {
        $command = CommandId::isValid($id) ? $this->commands->find(CommandId::parse($id)) : null;

        if ($command === null) {
            return new Json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Command not found.',
                ],
            ], Status::NOT_FOUND);
        }

        return new Json([
            'command' => CommandResource::fromRecord($command)->toArray(),
            'jobs' => array_map(
                static fn($job): array => JobResource::fromRecord($job)->toArray(),
                $this->jobs->listForCommand(CommandId::fromPrimaryKey($command->id)),
            ),
        ]);
    }
}
