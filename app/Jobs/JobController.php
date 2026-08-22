<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Http\Middleware\RequireAuthMiddleware;
use App\Http\Routing\AllowApiClients;
use App\Jobs\Api\JobResource;
use Tempest\Http\Responses\Json;
use Tempest\Http\Status;
use Tempest\Router\Get;
use Tempest\Router\WithMiddleware;

#[AllowApiClients]
#[WithMiddleware(RequireAuthMiddleware::class)]
final readonly class JobController
{
    public function __construct(
        private JobRepository $jobs,
    ) {}

    #[Get('/api/v1/jobs')]
    public function index(): Json
    {
        return new Json([
            'jobs' => array_map(
                static fn($job): array => JobResource::fromRecord($job)->toArray(),
                $this->jobs->listRecent(),
            ),
        ]);
    }

    #[Get('/api/v1/jobs/{id}')]
    public function show(string $id): Json
    {
        $job = JobId::isValid($id) ? $this->jobs->find(JobId::parse($id)) : null;

        if ($job === null) {
            return new Json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Job not found.',
                ],
            ], Status::NOT_FOUND);
        }

        return new Json(['job' => JobResource::fromRecord($job)->toArray()]);
    }
}
