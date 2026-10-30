<?php

declare(strict_types=1);

namespace App\Operations;

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
     */
    public function execute(Work $work, string $jobId): void;
}
