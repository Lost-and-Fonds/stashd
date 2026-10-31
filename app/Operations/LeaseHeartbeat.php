<?php

declare(strict_types=1);

namespace App\Operations;

use App\Operations\DTO\ExecutionContext;
use Tempest\Process\PendingProcess;
use Tempest\Process\ProcessExecutor;
use Tempest\Process\ProcessResult;

/**
 * Keep a lease alive while a long external tool runs without holding a transaction.
 *
 * A lost lease stops the child. External effects still need stable idempotency and
 * reconciliation; killing a process cannot undo effects it already performed.
 */
final readonly class LeaseHeartbeat
{
    /**
     * Use Tempest process supervision and Operations' database-clock renewals.
     */
    public function __construct(
        private ProcessExecutor $processes,
        private AttemptAuthority $authority,
        private LeaseConfig $lease,
    ) {}

    /**
     * Poll a tool and renew at one third of the configured lease interval.
     *
     * Monotonic host time schedules polls only; PostgreSQL alone grants authority.
     * A failed renewal or supervision error always stops and reaps the process.
     */
    public function run(ExecutionContext $context, PendingProcess $command): ProcessResult
    {
        if (! $this->authority->renew($context)) {
            throw new AuthorityLost('Cannot start a tool with revoked authority.');
        }

        $child = $this->processes->start($command);
        $interval = max(1, intdiv($this->lease->milliseconds, 3)) * 1_000_000;
        $next = hrtime(true) + $interval;
        try {
            while ($child->running) {
                if (hrtime(true) >= $next) {
                    if (! $this->authority->renew($context)) {
                        throw new AuthorityLost('Execution authority expired while the tool was running.');
                    }

                    $next = hrtime(true) + $interval;
                }

                usleep(min(10_000, max(1, intdiv($this->lease->milliseconds * 1000, 6))));
            }

            return $child->wait();
        } finally {
            $child->stop(0, 9);
        }
    }
}
