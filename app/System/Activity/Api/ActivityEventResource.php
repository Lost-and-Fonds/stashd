<?php

declare(strict_types=1);

namespace App\System\Activity\Api;

use App\Http\Api\ApiJson;
use App\System\Activity\ActivityEventRecord;

final readonly class ActivityEventResource
{
    public function __construct(
        private ActivityEventRecord $event,
    ) {}

    public static function fromRecord(ActivityEventRecord $event): self
    {
        return new self($event);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ApiJson::encode([
            'id' => (string) $this->event->id,
            'level' => $this->event->level->value,
            'type' => $this->event->type,
            'message' => $this->event->message,
            'entityType' => $this->event->entityType,
            'entityId' => $this->event->entityId,
            'stashId' => $this->event->stashId,
            'mediaItemId' => $this->event->mediaItemId,
            'broadcastId' => $this->event->broadcastId,
            'jobId' => $this->event->jobId,
            'commandId' => $this->event->commandId,
            'createdAt' => $this->event->createdAt,
        ]);
    }
}
