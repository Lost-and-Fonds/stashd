<?php

declare(strict_types=1);

namespace App\System\Activity;

use App\Http\Middleware\RequireAuthMiddleware;
use App\Http\Routing\AllowApiClients;
use App\System\Activity\Api\ActivityEventResource;
use Tempest\Http\Responses\Json;
use Tempest\Router\Get;
use Tempest\Router\WithMiddleware;

#[AllowApiClients]
#[WithMiddleware(RequireAuthMiddleware::class)]
final readonly class ActivityController
{
    private const int DEFAULT_LIMIT = 50;

    public function __construct(
        private ActivityEventRepository $events,
    ) {}

    #[Get('/api/v1/activity')]
    public function index(): Json
    {
        return new Json([
            'events' => array_map(
                static fn(ActivityEventRecord $event): array => ActivityEventResource::fromRecord($event)->toArray(),
                $this->events->listRecent(self::DEFAULT_LIMIT),
            ),
        ]);
    }
}
