<?php

declare(strict_types=1);

namespace App\System\Health;

use App\Http\Middleware\RequireAuthMiddleware;
use App\Http\Routing\AllowApiClients;
use Tempest\Http\Responses\Json;
use Tempest\Http\Status;
use Tempest\Router\Get;

#[AllowApiClients]
final readonly class HealthController
{
    public function __construct(
        private HealthService $health,
    ) {}

    #[Get('/health')]
    public function publicHealth(): Json
    {
        $report = $this->health->report();
        $status = $report->status === 'ok' ? Status::OK : Status::SERVICE_UNAVAILABLE;

        return new Json($report->toPublicArray(), $status);
    }

    #[Get('/api/v1/system/health', middleware: [RequireAuthMiddleware::class])]
    public function detailedHealth(): Json
    {
        $report = $this->health->report();
        $status = $report->status === 'ok' ? Status::OK : Status::SERVICE_UNAVAILABLE;

        return new Json($report->toDetailedArray(), $status);
    }
}
