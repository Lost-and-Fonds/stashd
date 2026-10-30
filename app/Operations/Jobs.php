<?php

namespace App\Operations;

/**
 * Expose durable admission while keeping Work separate from execution identity.
 */
final readonly class Jobs
{
    /**
     * Use the same runtime for legacy examples and canonical Work admission.
     */
    public function __construct(
        private JobRuntime $runtime,
    ) {}

    /**
     * Retain the legacy example's permanently reserved key contract.
     */
    public function admit(string $key, string $payload): string
    {
        return $this->runtime->admit($key, $payload);
    }

    /**
     * Return the durable Job admitted for or already performing this Work.
     *
     * Joining retains the original observation and promises no follow-up. The
     * department must durably retain unmet demand and reconcile satisfied
     * coverage after Job termination and process restart before requesting again.
     */
    public function request(Work $work, string $priority = 'routine'): string
    {
        return $this->runtime->admitWork($work, $priority);
    }
}
