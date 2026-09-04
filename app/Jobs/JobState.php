<?php

declare(strict_types=1);

namespace App\Jobs;

enum JobState: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Retrying = 'retrying';
    case Ready = 'ready';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** @return list<JobState> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::Cancelled],
            self::Processing => [self::Ready, self::Failed, self::Cancelled, self::Pending, self::Retrying],
            self::Retrying => [self::Pending, self::Processing, self::Failed, self::Cancelled],
            self::Ready, self::Failed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
