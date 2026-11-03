<?php

declare(strict_types=1);

namespace App\Operations;

/**
 * Jobs contain serializable input; injected handlers perform the work.
 */
interface Job
{
    /**
     * Equivalent work, including any mode that changes its meaning.
     */
    public function key(): string;

    /**
     * Identifies the immutable input snapshot, independently of work identity.
     */
    public function observation(): string;
}
