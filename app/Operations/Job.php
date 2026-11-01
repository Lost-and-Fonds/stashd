<?php

declare(strict_types=1);

namespace App\Operations;

/**
 * Describe serializable departmental work, without queue or workflow dependencies.
 *
 * Jobs must have public serializable properties; execution lives in a discovered
 * handler. Observation is demand, not another canonical Work identity.
 */
interface Job
{
    /**
     * Identify the operation and its observed input snapshot.
     */
    public function work(): Work;
}
