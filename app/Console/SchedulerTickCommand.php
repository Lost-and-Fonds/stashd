<?php

declare(strict_types=1);

namespace App\Console;

use App\System\Scheduler\RoutineDiscoveryScheduler;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\ExitCode;
use Tempest\Console\HasConsole;
use Tempest\Console\Schedule;
use Tempest\Console\Scheduler\Every;

final readonly class SchedulerTickCommand
{
    use HasConsole;

    public function __construct(
        private RoutineDiscoveryScheduler $scheduler,
    ) {}

    #[ConsoleCommand(
        name: 'stashd:scheduler-tick',
        description: 'Create routine discovery jobs for due stash inputs',
    )]
    #[Schedule(Every::MINUTE)]
    public function __invoke(): ExitCode
    {
        $count = $this->scheduler->runDueChecks();

        if ($count > 0) {
            $this->console->info("Scheduled {$count} routine preflight job(s).");
        }

        return ExitCode::SUCCESS;
    }
}
