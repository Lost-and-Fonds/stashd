<?php

declare(strict_types=1);

namespace App\Broadcasts;

final readonly class BroadcastTriggerResult
{
    /**
     * @param  list<array<string, mixed>>  $runs
     */
    public function __construct(
        public int $triggeredCount,
        public int $successCount,
        public int $failureCount,
        public array $runs,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'triggered_count' => $this->triggeredCount,
            'success_count' => $this->successCount,
            'failure_count' => $this->failureCount,
            'runs' => $this->runs,
        ];
    }
}

/**
 * Compatibility shell for the retired provider scan-trigger subsystem.
 *
 * External Broadcast plugins perform post-publication side effects in their
 * generic finalize phase. Historical trigger rows remain readable, but no
 * provider-specific trigger is created or executed by core.
 */
