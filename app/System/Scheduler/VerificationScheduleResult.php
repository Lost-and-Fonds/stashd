<?php

declare(strict_types=1);

namespace App\System\Scheduler;

final readonly class VerificationScheduleResult
{
    public function __construct(
        public int $eligible,
        public int $alreadyQueued,
        public int $dispatched,
        public bool $skippedStorageUnavailable,
        public bool $limitReached,
    ) {}

    /** @return array{eligible: int, already_queued: int, dispatched: int, skipped_storage_unavailable: bool, limit_reached: bool} */
    public function toArray(): array
    {
        return [
            'eligible' => $this->eligible,
            'already_queued' => $this->alreadyQueued,
            'dispatched' => $this->dispatched,
            'skipped_storage_unavailable' => $this->skippedStorageUnavailable,
            'limit_reached' => $this->limitReached,
        ];
    }
}
