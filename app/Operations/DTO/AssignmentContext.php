<?php

declare(strict_types=1);

namespace App\Operations\DTO;

/**
 * Correlate a handler with durable admission and its immutable observed input.
 *
 * These identifiers support idempotency; they never grant publication authority.
 */
final readonly class AssignmentContext
{
    /**
     * Keep the demand identity stable across delivery retries and process restarts.
     */
    public function __construct(
        public string $assignmentId,
        public string $demandId,
        public string $attemptId,
    ) {}
}
