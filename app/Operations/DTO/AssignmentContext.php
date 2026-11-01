<?php

declare(strict_types=1);

namespace App\Operations\DTO;

use App\Identity\Identifier;
use App\Identity\IdentifierType;

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
        public Identifier $assignmentId,
        public Identifier $demandId,
        public Identifier $attemptId,
    ) {
        $assignmentId->uuidFor(IdentifierType::Assignment);
        $demandId->uuidFor(IdentifierType::Demand);
        $attemptId->uuidFor(IdentifierType::Attempt);
    }
}
