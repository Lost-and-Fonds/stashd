<?php

declare(strict_types=1);

namespace App\Operations\DTO;

/**
 * Identify the exact PostgreSQL session lock granted by the upstream claim adapter.
 */
final readonly class ActivityOwnership
{
    /**
     * Include backend start time so operating-system PID reuse grants no authority.
     */
    public function __construct(
        public int $pid,
        public string $backendStart,
        public int $lockKey,
    ) {}
}
