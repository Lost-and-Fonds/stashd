<?php

declare(strict_types=1);

namespace App\Operations;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Tempest\Process\InvokedProcess;
use Tempest\Process\PendingProcess;
use Tempest\Process\ProcessExecutor;
use Throwable;

/**
 * Own a fixed set of continuous Operations consumers, not their Jobs or completion policy.
 * Callers must stop the pool before disposing its database, queues or storage.
 */
final class OperationsWorkerPool
{
    /** @var list<InvokedProcess> */
    private array $processes = [];
    /** @var list<int> */
    private array $pids = [];
    private int $owner;
    private bool $started = false;

    /**
     * Keep process execution in Tempest and record the only process allowed to supervise children.
     */
    public function __construct(
        private ProcessExecutor $executor,
    ) {
        $this->owner = posix_getpid();
    }

    /**
     * Start continuous consumers and wait for Messenger's readiness receipt from each child.
     * The supplied command must run operations:work --notify-ready without --once or --limit.
     * The optional tick lets the caller check cancellation during startup, without owning workers.
     * Startup failure stops every child already acquired before propagating the failure.
     */
    public function start(PendingProcess $command, int $count, ?Closure $tick = null): void
    {
        $this->requireOwner();
        if ($count < 1 || $this->started) {
            throw new InvalidArgumentException('Start a new Operations pool with a positive worker count.');
        }

        $this->started = true;
        try {
            for ($index = 0; $index < $count; $index++) {
                $process = $this->executor->start($command);
                $this->processes[] = $process;
                $pid = $process->pid;
                if ($pid === null) {
                    throw new RuntimeException('Operations worker started without a process identity.');
                }

                $this->pids[] = $pid;
                $deadline = hrtime(true) + 15_000_000_000;
                do {
                    $tick?->__invoke();
                    $this->assertRunning();
                    if (str_contains(PHP_EOL . $process->output, PHP_EOL . json_encode(['event' => 'ready', 'pid' => $pid], JSON_THROW_ON_ERROR) . PHP_EOL)) {
                        break;
                    }

                    if (hrtime(true) >= $deadline) {
                        throw new RuntimeException('Operations worker did not become ready before its startup deadline.');
                    }

                    usleep(20_000);
                } while (true);
            }
        } catch (Throwable $error) {
            $this->stop();
            throw $error;
        }
    }

    /**
     * Fail fast on any unexpected exit, including a clean exit while consumption was required.
     * Process liveness is availability, not proof that a handler is actively executing.
     */
    public function assertRunning(): void
    {
        $this->requireOwner();
        foreach ($this->processes as $index => $process) {
            if (! $process->running) {
                throw new RuntimeException('Operations worker ' . $this->pids[$index] . ' exited unexpectedly.');
            }
        }
    }

    /**
     * Return captured worker identities, which remain available after shutdown for independent checks.
     * These are not busy-worker counts or evidence that Jobs overlapped.
     *
     * @return list<int>
     */
    public function pids(): array
    {
        return $this->pids;
    }

    /**
     * Signal every consumer before waiting, then escalate and reap every owned process.
     * Cleanup continues after an individual failure and throws if termination cannot be confirmed.
     */
    public function stop(float $graceSeconds = 3): void
    {
        $this->requireOwner();
        $errors = [];
        foreach ($this->processes as $process) {
            try {
                if ($process->running) {
                    $process->signal(SIGTERM);
                }
            } catch (Throwable $error) {
                $errors[] = $error;
            }
        }

        $deadline = hrtime(true) + (int) (max(0, $graceSeconds) * 1_000_000_000);
        do {
            $running = false;
            foreach ($this->processes as $process) {
                try {
                    $running = $process->running || $running;
                } catch (Throwable $error) {
                    $errors[] = $error;
                }
            }

            if ($errors !== [] || ! $running || hrtime(true) >= $deadline) {
                break;
            }

            usleep(20_000);
        } while (true);

        foreach ($this->processes as $process) {
            try {
                $process->stop(0);
                try {
                    $process->wait();
                } catch (ProcessSignaledException) {
                    // An externally killed worker is a run failure, not an unconfirmed reap.
                }

                if ($process->running) {
                    throw new RuntimeException('An Operations worker is still running after shutdown.');
                }
            } catch (Throwable $error) {
                $errors[] = $error;
            }
        }

        if ($errors !== []) {
            throw new RuntimeException('Operations worker cleanup could not be confirmed.', previous: $errors[0]);
        }
    }

    /**
     * Refuse supervision from an inherited renderer copy after Laravel Prompts forks.
     */
    private function requireOwner(): void
    {
        if (getmypid() !== $this->owner) {
            throw new RuntimeException('Only the process that created the Operations pool may supervise it.');
        }
    }
}
