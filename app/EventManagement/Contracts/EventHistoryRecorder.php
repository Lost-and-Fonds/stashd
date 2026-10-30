<?php

declare(strict_types=1);

namespace App\EventManagement\Contracts;

/**
 * Defines the future boundary for selecting and recording operational events.
 * Implementations must document their transaction and durability guarantees.
 */
interface EventHistoryRecorder
{
    /**
     * Records a selected event according to an explicitly documented storage policy.
     * Dispatch alone does not guarantee that this call follows a committed transaction.
     */
    public function record(object $event): void;
}
