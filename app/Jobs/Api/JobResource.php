<?php

declare(strict_types=1);

namespace App\Jobs\Api;

use App\Http\Api\ApiJson;
use App\Jobs\JobRecord;
use App\Support\DurationSeconds;

final readonly class JobResource
{
    public function __construct(
        private JobRecord $job,
    ) {}

    public static function fromRecord(JobRecord $job): self
    {
        return new self($job);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ApiJson::encode([
            'id' => (string) $this->job->id,
            'commandId' => $this->job->commandId === null ? null : (string) $this->job->commandId,
            'intent' => $this->job->intent->value,
            'entityType' => $this->job->entityType,
            'entityId' => $this->job->entityId,
            'state' => $this->job->state->value,
            'priority' => $this->job->priority,
            'attempts' => $this->job->attempts,
            'maxAttempts' => $this->job->maxAttempts,
            'scheduledAt' => $this->job->scheduledAt,
            'startedAt' => $this->job->startedAt,
            'finishedAt' => $this->job->finishedAt,
            'heartbeatAt' => $this->job->heartbeatAt,
            'progressCurrent' => $this->job->progressCurrent,
            'progressTotal' => $this->job->progressTotal,
            'progressPercent' => $this->job->progressPercent,
            'progressLabel' => $this->job->progressLabel,
            'progressEtaSeconds' => DurationSeconds::toSeconds($this->job->progressEtaSeconds),
            'progressRate' => $this->job->progressRate,
            'lastError' => $this->job->lastError,
            'payload' => $this->encodeForApi($this->job->payload),
            'createdAt' => $this->job->createdAt,
            'updatedAt' => $this->job->updatedAt,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    private function encodeForApi(?array $data): ?array
    {
        return $data === null ? null : ApiJson::encode($data);
    }
}
