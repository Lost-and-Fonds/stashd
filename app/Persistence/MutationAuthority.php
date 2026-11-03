<?php

declare(strict_types=1);

namespace App\Persistence;

/**
 * Require current execution authority around a fenced database mutation.
 */
interface MutationAuthority
{
    /**
     * Reject missing or stale authority and roll back the mutation if authority is lost.
     * Join a caller's transaction without committing it.
     *
     * @template T
     * @param callable(): T $mutation
     * @return T
     */
    public function protectCurrent(callable $mutation): mixed;
}
