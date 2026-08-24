<?php

declare(strict_types=1);

namespace App\MediaServers;

/** @internal Retained because the released domain migration references it. */
enum MediaServerConnectionState: string
{
    case Ready = 'ready';
    case Failed = 'failed';
    case Disabled = 'disabled';

    /** @return list<MediaServerConnectionState> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Ready => [self::Failed, self::Disabled],
            self::Failed => [self::Ready, self::Disabled],
            self::Disabled => [self::Ready, self::Failed],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
