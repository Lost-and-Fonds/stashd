<?php

declare(strict_types=1);

namespace App\Console;

use App\Jobs\MessengerWorkerRunner;
use App\Jobs\MessengerTransportRegistry;
use App\Jobs\WorkerPoolManager;
use Tempest\Console\ConsoleArgument;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\ExitCode;
use Tempest\Console\HasConsole;
use Tempest\Process\ProcessExecutor;

final readonly class StashdRuntimeCommand
{
    use HasConsole;

    public function __construct(
        private MessengerWorkerRunner $workers,
        private WorkerPoolManager $pools,
        private MessengerTransportRegistry $transports,
        private ProcessExecutor $processes,
    ) {}

    #[ConsoleCommand(
        name: 'stashd',
        description: 'Stashd runtime roles: all, serve, worker, scheduler',
    )]
    public function __invoke(
        #[ConsoleArgument(description: 'Role to run', aliases: ['role'])]
        string $role = 'all',
        #[ConsoleArgument(description: 'Worker workload (interactive or background)')]
        ?string $workload = null,
    ): ExitCode {
        return match ($role) {
            'all' => $this->runAll(),
            'serve' => $this->serve(),
            'worker' => $this->runWorker($workload),
            'scheduler' => $this->runScheduler(),
            default => $this->unknownRole($role),
        };
    }

    private function runAll(): ExitCode
    {
        $this->console->info('Starting Stashd all-in-one runtime (supervisord expected in Docker).');
        $this->console->info('Local dev: run `stashd serve`, `stashd worker`, and `stashd scheduler` in separate terminals.');

        return $this->serve();
    }

    private function runWorker(?string $workload): ExitCode
    {
        if ($workload !== null && ! in_array($workload, ['interactive', 'background'], true)) {
            $this->console->error("Unknown worker workload: {$workload}");
            $this->console->info('Valid workloads: interactive, background');

            return ExitCode::ERROR;
        }

        $this->console->info('Messenger worker started' . ($workload !== null ? " ({$workload})" : '') . '.');
        $this->pools->run($workload ?? 'background', $this->workers, $this->transports);

        return ExitCode::SUCCESS;
    }

    private function serve(): ExitCode
    {
        $root = dirname(__DIR__, 2);
        $this->processes->run("frankenphp run --config {$root}/docker/Caddyfile");

        return ExitCode::SUCCESS;
    }

    private function runScheduler(): ExitCode
    {
        $this->console->info('Scheduler started.');

        for (;;) {
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
