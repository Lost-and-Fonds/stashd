<?php

declare(strict_types=1);

namespace App\Operations\Durable;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/**
 * Execute one admitted observation through the discovered departmental handler.
 */
interface AssignmentActivity
{
    /**
     * Return the next retained observation, or null when admission is satisfied.
     */
    #[AsActivityMethod('operations.execute')]
    public function execute(string $assignment, string $demand): ?string;
}
