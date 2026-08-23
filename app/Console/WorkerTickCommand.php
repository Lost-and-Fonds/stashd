<?php

declare(strict_types=1);

namespace App\Console;

use App\Jobs\JobLane;
use App\Jobs\JobWorkerService;
use Tempest\Console\ConsoleArgument;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\HasConsole;

final readonly class WorkerTickCommand
{
    use HasConsole;

    /** Exit code telling the worker loop the queue was empty, so it can poll less aggressively. */
    public const int EXIT_IDLE = 10;

    public const int EXIT_INVALID_LANE = 2;

    public function __construct(
        private JobWorkerService $worker,
    ) {}

    #[ConsoleCommand(
        name: 'stashd:worker-tick',
        description: 'Process one pending job if available',
    )]
    public function __invoke(
        #[ConsoleArgument(description: 'Only claim jobs in this lane (interactive, discovery, bulk); omit for all lanes')]
        ?string $lane = null,
    ): int {
        $jobLane = null;

        if ($lane !== null) {
            $jobLane = JobLane::tryFrom($lane);

            if ($jobLane === null) {
                $this->console->error("Unknown worker lane: {$lane}");

                return self::EXIT_INVALID_LANE;
            }
        }

        $processed = $this->worker->processNextJob($jobLane);

        return $processed ? 0 : self::EXIT_IDLE;
    }
}
