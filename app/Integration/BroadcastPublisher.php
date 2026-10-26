<?php

declare(strict_types=1);

namespace App\Integration;

/**
 * Run one in-process publication using only invocation-scoped host capabilities.
 */
interface BroadcastPublisher
{
    /**
     * Consume the fixed collection and return complete or not-applicable file status.
     *
     * Throw on failure. Destination side effects may remain, but reports are discarded.
     * Staged output adoption is outside this copy-only integration slice.
     */
    public function publish(BroadcastInvocation $invocation, array $settings): FileReportStatus;
}
