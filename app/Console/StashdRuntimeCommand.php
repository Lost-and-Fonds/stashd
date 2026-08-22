<?php

declare(strict_types=1);

namespace App\Console;

use App\Jobs\JobLane;
use App\System\FrankenPhp\FrankenPhpProcessLauncher;
use Tempest\Console\ConsoleArgument;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\ExitCode;
use Tempest\Console\HasConsole;
use Tempest\Process\ProcessExecutor;

final readonly class StashdRuntimeCommand
{
    use HasConsole;

    public function __construct(
        private ProcessExecutor $processes,
        private FrankenPhpProcessLauncher $frankenPhp,
    ) {}

    #[ConsoleCommand(
        name: 'stashd',
        description: 'Stashd runtime roles: all, serve, worker, scheduler',
    )]
    public function __invoke(
        #[ConsoleArgument(description: 'Role to run', aliases: ['role'])]
        string $role = 'all',
        #[ConsoleArgument(description: 'Worker lane (interactive, discovery, bulk); omit to process all lanes')]
        ?string $lane = null,
    ): ExitCode {
        return match ($role) {
            'all' => $this->runAll(),
            'serve' => $this->frankenPhp->serve(),
            'worker' => $this->runWorker($lane),
            'scheduler' => $this->runScheduler(),
            default => $this->unknownRole($role),
        };
    }

    private function runAll(): ExitCode
    {
        $this->console->info('Starting Stashd all-in-one runtime (supervisord expected in Docker).');
        $this->console->warn('Local dev: run `stashd serve`, `stashd worker`, and `stashd scheduler` in separate terminals.');

        return $this->frankenPhp->serve();
    }

    private function runWorker(?string $lane): ExitCode
    {
        if ($lane !== null && JobLane::tryFrom($lane) === null) {
            $this->console->error("Unknown worker lane: {$lane}");
            $this->console->info('Valid lanes: interactive, discovery, bulk');

            return ExitCode::ERROR;
        }

        $this->console->info('Job worker started' . ($lane !== null ? " (lane: {$lane})" : '') . '. Polling for pending jobs…');

        $tick = 'php tempest stashd:worker-tick' . ($lane !== null ? " {$lane}" : '');

        while (true) {
            $result = $this->processes->run($tick);

            // Idle queues poll gently: with one loop per lane, a 2s cadence
            // per loop adds up to constant PHP boots on a small NAS.
            sleep($result->exitCode === 0 ? 2 : 5);
        }
    }

    private function runScheduler(): ExitCode
    {
        $this->console->info('Scheduler started.');

        while (true) {
            $this->processes->run('php tempest schedule:run');
            sleep(60);
        }
    }

    private function unknownRole(string $role): ExitCode
    {
        $this->console->error("Unknown stashd role: {$role}");
        $this->console->info('Valid roles: all, serve, worker, scheduler');

        return ExitCode::ERROR;
    }
}
