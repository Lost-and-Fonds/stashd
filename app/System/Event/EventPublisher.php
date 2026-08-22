<?php

declare(strict_types=1);

namespace App\System\Event;

use App\Jobs\Api\JobRealtimeResource;
use App\Jobs\JobRecord;
use App\System\Activity\ActivityEventRecord;
use App\System\Activity\Api\ActivityEventResource;
use App\System\Secret\SecretsService;

final readonly class EventPublisher
{
    public function __construct(
        private MercurePublisher $mercure,
        private SecretsService $secrets,
    ) {}

    public function jobCreated(JobRecord $job): void
    {
        $this->jobChanged('job.created', $job);
    }

    public function jobProgress(JobRecord $job): void
    {
        $this->jobChanged('job.progress', $job);
    }

    public function jobCompleted(JobRecord $job): void
    {
        $this->jobChanged('job.completed', $job);
    }

    public function jobFailed(JobRecord $job): void
    {
        $this->jobChanged('job.failed', $job);
    }

    public function activityCreated(ActivityEventRecord $event): void
    {
        $this->mercure->publish('activity.created', ActivityEventResource::fromRecord($event)->toArray());
    }

    private function jobChanged(string $event, JobRecord $job): void
    {
        $data = JobRealtimeResource::fromRecord($job)->toArray();

        if (is_string($data['last_error'])) {
            $data['last_error'] = $this->secrets->redact($data['last_error']);
        }

        $this->mercure->publish($event, $data);
    }
}
