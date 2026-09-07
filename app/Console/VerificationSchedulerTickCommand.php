<?php

declare(strict_types=1);

namespace App\Console;

use App\System\Scheduler\VerificationScheduler;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\ExitCode;
use Tempest\Console\HasConsole;
use Tempest\Console\Schedule;
use Tempest\Console\Scheduler\Every;

final readonly class VerificationSchedulerTickCommand
{
    use HasConsole;

    public function __construct(private VerificationScheduler $scheduler) {}

    #[ConsoleCommand(
        name: 'stashd:verification-tick',
        description: 'Create verification jobs for unverified and due Vault assets',
    )]
    #[Schedule(Every::DAY)]
    public function __invoke(): ExitCode
    {
        $result = $this->scheduler->run();

        if ($result->skippedStorageUnavailable) {
            $this->console->warning('Skipped automatic verification because Vault storage is unavailable.');
        } elseif ($result->dispatched > 0) {
            $this->console->info("Scheduled {$result->dispatched} automatic verification job(s).");
        }

        return ExitCode::SUCCESS;
    }
}
