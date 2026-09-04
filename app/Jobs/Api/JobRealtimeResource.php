<?php

declare(strict_types=1);

namespace App\Jobs\Api;

use App\Http\Api\ApiJson;
use App\Jobs\JobRecord;
use App\Support\DurationSeconds;

/** Safe subset of a job for the private real-time stream. */
final readonly class JobRealtimeResource
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
        $payload = $this->job->payload ?? [];
        $mediaItemId = $payload['media_item_id'] ?? ($this->job->entityType === 'media_item' ? $this->job->entityId : null);
        $stashId = $payload['stash_id'] ?? null;

        return ApiJson::encode([
            'id' => (string) $this->job->id,
            'type' => $this->job->type(),
            'entityType' => $this->job->entityType,
            'entityId' => $this->job->entityId,
            'stashId' => is_string($stashId) ? $stashId : null,
            'mediaItemId' => is_string($mediaItemId) ? $mediaItemId : null,
            'state' => $this->job->state->value,
            'progressCurrent' => $this->job->progressCurrent,
            'progressTotal' => $this->job->progressTotal,
            'progressPercent' => $this->job->progressPercent,
            'progressLabel' => $this->job->progressLabel,
            'progressEtaSeconds' => DurationSeconds::toSeconds($this->job->progressEtaSeconds),
            'progressRate' => $this->job->progressRate,
            'lastError' => $this->job->lastError,
            'startedAt' => $this->job->startedAt,
            'finishedAt' => $this->job->finishedAt,
            'createdAt' => $this->job->createdAt,
            'updatedAt' => $this->job->updatedAt,
        ]);
    }
}
