<?php

declare(strict_types=1);

namespace App\Operations\DTO;

use DateTimeImmutable;

/**
 * Describe the persisted execution episode supplied to a Work executor.
 *
 * The Attempt ID is also the current claim identity. Generation identifies a
 * delivery revision, not a monotonic fencing token. The initial lease deadline
 * does not grant renewal, cancellation or permission to perform external effects.
 */
final readonly class ExecutionContext
{
    /**
     * Retain execution metadata for correlation, without granting claim authority.
     */
    public function __construct(
        public string $jobId,
        public string $attemptId,
        public int $generation,
        public DateTimeImmutable $leaseExpiresAt,
    ) {}
}
