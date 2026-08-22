<?php

declare(strict_types=1);

namespace App\Console;

use App\System\Boot\SqliteConfigurator;
use App\System\Scheduler\RoutineDiscoveryScheduler;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\ExitCode;
use Tempest\Console\HasConsole;
use Tempest\Console\Schedule;
use Tempest\Console\Scheduler\Every;
use Tempest\Database\Config\DatabaseConfig;

final readonly class SchedulerTickCommand
{
    use HasConsole;

    public function __construct(
        private RoutineDiscoveryScheduler $scheduler,
        private SqliteConfigurator $sqlite,
        private DatabaseConfig $databaseConfig,
    ) {}

    #[ConsoleCommand(
        name: 'stashd:scheduler-tick',
        description: 'Create routine discovery jobs for due stash inputs',
    )]
    #[Schedule(Every::MINUTE)]
    public function __invoke(): ExitCode
    {
        // Fresh CLI process every tick (schedule:run, invoked every 60s by
        // App\Console\StashdRuntimeCommand::runScheduler) — same missing
        // busy_timeout pragma as TempestPsr7Bridge::run().
        $this->sqlite->configure($this->databaseConfig);

        $count = $this->scheduler->runDueChecks();

        if ($count > 0) {
            $this->console->info("Scheduled {$count} routine preflight job(s).");
        }

        return ExitCode::SUCCESS;
    }
}
