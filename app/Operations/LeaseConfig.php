<?php

declare(strict_types=1);

namespace App\Operations;

/**
 * Bound execution ownership independently of native transport visibility.
 */
final readonly class LeaseConfig
{
    /**
     * Require a positive lease; workers should renew well before this interval.
     */
    public function __construct(
        public int $milliseconds = 60_000,
    ) {
        if ($milliseconds < 1) {
            throw new \InvalidArgumentException('The execution lease must be positive.');
        }
    }
}
