<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\MediaServers\MediaServerClientRegistry;
use App\MediaServers\MediaServerConnectionRepository;
use App\MediaServers\MediaServerConnectionSecrets;
use App\MediaServers\MediaServerConnectionService;
use App\Support\PrefixedUlid;
use App\System\Secret\SecretsService;
use App\System\State\StateTransitionService;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class BroadcastTriggerResult
{
    /**
     * @param list<array<string, mixed>> $runs
     */
    public function __construct(
        public int $triggeredCount,
        public int $successCount,
        public int $failureCount,
        public array $runs,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'triggered_count' => $this->triggeredCount,
            'success_count' => $this->successCount,
            'failure_count' => $this->failureCount,
            'runs' => $this->runs,
        ];
    }
}

/** Executes media-server scan triggers separately from broadcast file validity. */
final readonly class BroadcastTriggerService
{
    public function __construct(
        private BroadcastTriggerRepository $triggers,
        private BroadcastTriggerRunRepository $triggerRuns,
        private MediaServerConnectionRepository $connections,
        private MediaServerConnectionService $connectionService,
        private MediaServerClientRegistry $clients,
        private MediaServerConnectionSecrets $tokens,
        private StateTransitionService $transitions,
        private SecretsService $secrets,
    ) {
    }

    public function ensureScanTrigger(BroadcastRecord $broadcast): ?\App\Broadcasts\BroadcastTriggerRecord
    {
        $type = $this->triggerTypeForBroadcast($broadcast);

        if ($type === null) {
            return null;
        }

        $settings = $this->broadcastSettings($broadcast);
        $connectionId = trim((string) ($settings['media_server_connection_id'] ?? ''));

        if ($connectionId === '') {
            return null;
        }

        $existing = $this->triggers->findEnabledScanTrigger(
            BroadcastId::fromPrimaryKey($broadcast->id),
            $type,
        );

        if ($existing !== null) {
            return $existing;
        }

        return $this->triggers->create(
            broadcastId: BroadcastId::fromPrimaryKey($broadcast->id),
            type: $type,
            settings: ['mediaServerConnectionId' => $connectionId],
        );
    }

    public function execute(BroadcastRecord $broadcast, string $reason = 'manual'): BroadcastTriggerResult
    {
        $trigger = $this->ensureScanTrigger($broadcast);

        if ($trigger === null || ! $trigger->enabled) {
            return new BroadcastTriggerResult(0, 0, 0, []);
        }

        $connectionId = $trigger->settings?->mediaServerConnectionId ?? '';

        if ($connectionId === '') {
            return new BroadcastTriggerResult(0, 0, 0, []);
        }

        $connection = $this->connections->find(PrefixedUlid::parse($connectionId));

        if ($connection === null) {
            $this->markTriggerFailed($trigger, 'media_server_not_found');

            return new BroadcastTriggerResult(1, 0, 1, [[
                'trigger_id' => (string) $trigger->id,
                'ok' => false,
                'error' => 'media_server_not_found',
            ]]);
        }

        $library = $this->connectionService->libraryFromSettings($connection);

        if ($library === null) {
            $this->markTriggerFailed($trigger, 'media_server_library_not_configured');

            return new BroadcastTriggerResult(1, 0, 1, [[
                'trigger_id' => (string) $trigger->id,
                'ok' => false,
                'error' => 'media_server_library_not_configured',
            ]]);
        }

        $run = $this->triggerRuns->create(
            triggerId: BroadcastTriggerId::fromPrimaryKey($trigger->id),
            reason: $reason,
        );
        $this->transitionRun($run, BroadcastTriggerRunState::Processing);

        try {
            $token = $this->tokens->resolve($connection);

            if ($token === null || trim($token) === '') {
                throw BroadcastException::withCode('media_server_token_missing', 'Media server token missing.');
            }

            $broadcastRoot = null;
            $broadcastSettings = $this->broadcastSettings($broadcast);
            if (isset($broadcastSettings['scan_path'])) {
                $broadcastRoot = (string) $broadcastSettings['scan_path'];
            }

            $result = $this->clients->clientFor($connection)->triggerScan(
                $connection,
                $token,
                $library,
                $broadcastRoot,
            );

            $run->finishedAt = DateTime::now(Timezone::UTC);
            $run->responseSummary = $this->secrets->redact($result->message);

            if ($result->ok) {
                $this->transitionRun($run, BroadcastTriggerRunState::Ready);
                $trigger->lastTriggeredAt = DateTime::now(Timezone::UTC);
                $trigger->lastSuccessAt = DateTime::now(Timezone::UTC);
                $trigger->lastError = null;

                if ($trigger->state !== BroadcastTriggerState::Ready) {
                    $this->transitions->transitionBroadcastTrigger($trigger, BroadcastTriggerState::Ready);
                } else {
                    $this->triggers->save($trigger);
                }

                return new BroadcastTriggerResult(1, 1, 0, [[
                    'trigger_id' => (string) $trigger->id,
                    'run_id' => (string) $run->id,
                    'ok' => true,
                    'message' => $run->responseSummary,
                ]]);
            }

            $run->error = $this->secrets->redact($result->message);
            $this->transitionRun($run, BroadcastTriggerRunState::Failed);
            $this->markTriggerFailed($trigger, $run->error);

            return new BroadcastTriggerResult(1, 0, 1, [[
                'trigger_id' => (string) $trigger->id,
                'run_id' => (string) $run->id,
                'ok' => false,
                'error' => $run->error,
            ]]);
        } catch (\Throwable $throwable) {
            $run->finishedAt = DateTime::now(Timezone::UTC);
            $run->error = $this->secrets->redact($throwable->getMessage());
            $this->transitionRun($run, BroadcastTriggerRunState::Failed);
            $this->markTriggerFailed($trigger, $run->error);

            return new BroadcastTriggerResult(1, 0, 1, [[
                'trigger_id' => (string) $trigger->id,
                'run_id' => (string) $run->id,
                'ok' => false,
                'error' => $run->error,
            ]]);
        }
    }

    private function triggerTypeForBroadcast(BroadcastRecord $broadcast): ?BroadcastTriggerType
    {
        return match ($broadcast->type) {
            'plex' => BroadcastTriggerType::PlexScan,
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function broadcastSettings(BroadcastRecord $broadcast): array
    {
        return $broadcast->settings ?? [];
    }

    private function markTriggerFailed(\App\Broadcasts\BroadcastTriggerRecord $trigger, string $error): void
    {
        $trigger->lastTriggeredAt = DateTime::now(Timezone::UTC);
        $trigger->lastFailureAt = DateTime::now(Timezone::UTC);
        $trigger->lastError = $this->secrets->redact($error);

        if ($trigger->state !== BroadcastTriggerState::Failed) {
            $this->transitions->transitionBroadcastTrigger($trigger, BroadcastTriggerState::Failed);
        } else {
            $this->triggers->save($trigger);
        }
    }

    private function transitionRun(
        \App\Broadcasts\BroadcastTriggerRunRecord $run,
        BroadcastTriggerRunState $next,
    ): void {
        if ($run->state->canTransitionTo($next)) {
            $this->transitions->transitionBroadcastTriggerRun($run, $next);
        }
    }
}
