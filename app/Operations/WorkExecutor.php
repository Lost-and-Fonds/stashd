<?php

declare(strict_types=1);

namespace App\Operations;

use App\Operations\DTO\ExecutionContext;

/**
 * Execute registered domain Work after Operations has persisted its claim.
 *
 * Implementations must reject unsupported types and make external effects
 * idempotent or independently fenced. A Job claim cannot fence remote systems.
 */
interface WorkExecutor
{
    /**
     * Perform the requested operation, throwing when its outcome is unsuccessful.
     *
     * Context describes this Attempt; it does not authorize external effects.
     */
    public function execute(Work $work, ExecutionContext $context): void;
}
