<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Commands\CommandRecord;
use App\Commands\CommandRepository;
use App\Commands\CommandState;
use App\Jobs\JobHandler;
use App\Jobs\JobHandlerContext;
use App\Jobs\JobIntent;
use App\Jobs\JobProgressUpdate;
use App\Jobs\JobRecord;
use App\Jobs\JobRepository;
use App\Jobs\JobState;
use App\System\Activity\ActivityEventService;
use App\System\Event\EventPublisher;
use App\System\State\StateTransitionService;
use App\Vault\AssetId;
use App\Vault\VerifyVaultAssets;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class VerifyVaultJobHandler implements JobHandler
{
    private const float PROGRESS_INTERVAL_SECONDS = 5.0;

    public function __construct(
        private VerifyVaultAssets $verify,
        private CommandRepository $commands,
        private JobRepository $jobs,
        private StateTransitionService $transitions,
        private ActivityEventService $activity,
        private EventPublisher $publisher,
    ) {}

    public function intent(): JobIntent
    {
        return JobIntent::VerifyVault;
    }

    public function handle(JobRecord $job, JobHandlerContext $context): void
    {
        $command = $this->requireCommand($job);
        $this->transitions->transitionCommand($command, CommandState::Running);
        $context->heartbeat($job);

        $payload = $job->payload ?? [];

        if (isset($payload['asset_id']) && is_string($payload['asset_id']) && $payload['asset_id'] !== '') {
            $outcome = $this->verify->verifyAsset(AssetId::parse($payload['asset_id']));
            $result = [
                'scope' => 'asset',
                'asset_id' => $payload['asset_id'],
                'outcome' => $outcome->value,
            ];
        } else {
            $lastProgressAt = 0.0;
            $verifyResult = $this->verify->verifyAll(function (int $checked, int $total) use ($context, $job, &$lastProgressAt): void {
                $now = microtime(true);

                if ($checked !== $total && $now - $lastProgressAt < self::PROGRESS_INTERVAL_SECONDS) {
                    return;
                }

                $context->progress($job, JobProgressUpdate::ofSteps($checked, $total, 'Verifying Vault assets'));
                $lastProgressAt = $now;
            });
            $result = ['scope' => 'vault', ...$verifyResult->toArray()];
        }

        $command->result = $result;
        $this->commands->save($command);

        $job->progressCurrent = 1;
        $job->progressTotal = 1;
        $job->progressPercent = 100.0;
        $job->progressLabel = 'Vault verification complete';
        $job->finishedAt = DateTime::now(Timezone::UTC);
        $this->jobs->save($job);
        $context->progress($job, JobProgressUpdate::ofSteps(1, 1, $job->progressLabel));

        $this->transitions->transitionJob($job, JobState::Ready);
        $this->transitions->transitionCommand($command, CommandState::Completed);
        $this->activity->vaultVerifyCompleted($command, $job, $result);
        $this->publisher->jobCompleted($job);
        $this->activity->commandCompleted($command);
    }

    private function requireCommand(JobRecord $job): CommandRecord
    {
        if ($job->commandId === null) {
            throw new \RuntimeException('Verify vault job is missing commandId.');
        }

        return $this->commands->find($job->commandId)
            ?? throw new \RuntimeException('Verify vault command not found.');
    }
}
