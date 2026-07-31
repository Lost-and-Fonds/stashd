<?php

declare(strict_types=1);

namespace App\Console;

use App\Jobs\JobLane;
use App\Jobs\JobWorkerService;
use App\System\Boot\SqliteConfigurator;
use Tempest\Console\ConsoleArgument;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\HasConsole;
use Tempest\Database\Config\DatabaseConfig;

final readonly class WorkerTickCommand
{
    use HasConsole;

    /** Exit code telling the worker loop the queue was empty, so it can poll less aggressively. */
    public const int EXIT_IDLE = 10;

    public const int EXIT_INVALID_LANE = 2;

    public function __construct(
        private JobWorkerService $worker,
        private SqliteConfigurator $sqlite,
        private DatabaseConfig $databaseConfig,
    ) {
    }

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

        // Fresh CLI process every tick (App\Console\StashdRuntimeCommand::runWorker
        // shells out every 2s) — its PDO connection never gets stashd:boot's
        // busy_timeout pragma, same gap as TempestPsr7Bridge::run().
        $this->sqlite->configure($this->databaseConfig);

        $processed = $this->worker->processNextJob($jobLane);

        return $processed ? 0 : self::EXIT_IDLE;
    }
}
