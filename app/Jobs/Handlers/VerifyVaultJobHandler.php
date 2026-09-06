<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Fixity\VerifyAssetOutcome;
use App\Fixity\VerifyVaultAssets;
use App\Jobs\JobHandler;
use App\Jobs\JobProgressReporter;
use App\Jobs\JobProgressUpdate;
use App\Jobs\JobRecord;
use App\Jobs\JobRepository;
use App\Vault\AssetId;
use RuntimeException;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class VerifyVaultJobHandler implements JobHandler
{
    private const float PROGRESS_INTERVAL_SECONDS = 5.0;

    public function __construct(
        private VerifyVaultAssets $verify,
        private JobRepository $jobs,
    ) {}

    public function handle(JobRecord $job, JobProgressReporter $context): void
    {

        $payload = $job->payload ?? [];

        if (isset($payload['asset_id']) && is_string($payload['asset_id']) && $payload['asset_id'] !== '') {
            $verification = $this->verify->verifyAsset(AssetId::parse($payload['asset_id']), (string) $job->id);
            $outcome = $verification->outcome;

            if ($outcome === VerifyAssetOutcome::StorageUnavailable) {
                throw new RuntimeException('Vault storage is unavailable; verification was not attempted.');
            }

            $result = [
                'scope' => 'asset',
                'asset_id' => $payload['asset_id'],
                'outcome' => $outcome->value,
                'expected_checksum' => $verification->expectedChecksum,
                'observed_checksum' => $verification->observedChecksum,
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
            }, (string) $job->id);

            if ($verifyResult->storageUnavailable) {
                throw new RuntimeException('Vault storage is unavailable; verification was not attempted.');
            }

            $result = ['scope' => 'vault', ...$verifyResult->toArray()];
        }

        $job->payload = [...$payload, 'result' => $result];


        $job->progressCurrent = 1;
        $job->progressTotal = 1;
        $job->progressPercent = 100.0;
        $job->progressLabel = 'Vault verification complete';
        $job->finishedAt = DateTime::now(Timezone::UTC);
        $this->jobs->save($job);
        $context->progress($job, JobProgressUpdate::ofSteps(1, 1, $job->progressLabel));

    }
}
