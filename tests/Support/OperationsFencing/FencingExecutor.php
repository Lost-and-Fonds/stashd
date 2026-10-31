<?php

declare(strict_types=1);

namespace Tests\Support\OperationsFencing;

use App\Operations\AttemptAuthority;
use App\Operations\DTO\ExecutionContext;
use App\Operations\ExecutionArtifacts;
use App\Operations\Work;
use App\Operations\WorkExecutor;

/**
 * Pause real domain execution and route controlled host requests through production.
 */
final readonly class FencingExecutor implements WorkExecutor
{
    /**
     * Keep only IPC paths and the actual host services in the living executor.
     */
    public function __construct(
        private string $prefix,
        private AttemptAuthority $authority,
        private ExecutionArtifacts $host,
        private \App\Operations\LeaseHeartbeat $heartbeat,
    ) {}

    /**
     * Execute commands until completion, a genuine handler error, or supervisor kill.
     */
    public function execute(Work $work, ExecutionContext $context): void
    {
        file_put_contents($this->prefix . '.entered.tmp', json_encode($context, JSON_THROW_ON_ERROR));
        rename($this->prefix . '.entered.tmp', $this->prefix . '.entered');
        $deadline = microtime(true) + 100;
        while (microtime(true) < $deadline) {
            if (! is_file($this->prefix . '.command')) {
                usleep(10_000);
                continue;
            }

            $command = json_decode(file_get_contents($this->prefix . '.command'), true, flags: JSON_THROW_ON_ERROR);
            unlink($this->prefix . '.command');
            if ($command['action'] === 'complete') {
                return;
            }

            if ($command['action'] === 'fail') {
                file_put_contents($this->prefix . '.thrown', $command['message']);
                throw new \RuntimeException($command['message']);
            }

            if (isset($command['barrier'])) {
                touch($this->prefix . '.boundary');
                while (! is_file($this->prefix . '.release')) {
                    usleep(10_000);
                }

                unlink($this->prefix . '.release');
            }

            try {
                $value = $command['value'] ?? '';
                $result = match ($command['action']) {
                    'renew' => $this->renew($context),
                    'slow_checkpoint' => $this->slow($context),
                    'heartbeat' => [
                        'accepted' => $this->heartbeat->run(
                            $context,
                            new \Tempest\Process\PendingProcess(command: [PHP_BINARY, '-r', 'usleep(6500000);'], timeout: \Tempest\DateTime\Duration::seconds(10)),
                        )->successful(),
                    ],
                    'reconcile' => ['verified_custody_keys' => $this->host->reconcile($context)],
                    default => $this->write($context, $command['action'], $value),
                };
            } catch (\Throwable $error) {
                $result = ['accepted' => false, 'reason' => $error->getMessage()];
            }

            file_put_contents($this->prefix . '.response.tmp', json_encode($result, JSON_THROW_ON_ERROR));
            rename($this->prefix . '.response.tmp', $this->prefix . '.response');
        }

        throw new \RuntimeException('Executor supervision deadline exceeded.');
    }

    /**
     * Return only the real renewal decision's PostgreSQL evidence.
     */
    private function renew(ExecutionContext $context): array
    {
        $decision = $this->authority->renewal($context);

        return ['accepted' => $decision->accepted, 'decided_at' => $decision->decidedAt->format('Y-m-d H:i:s.uP')];
    }

    /**
     * Deliberately cross expiry inside a guarded database unit to prove rollback.
     */
    private function slow(ExecutionContext $context): array
    {
        $this->authority->protect($context, function (\Doctrine\DBAL\Connection $db) use ($context): void {
            $db->executeQuery('SELECT pg_sleep(5)');
            $db->executeStatement("INSERT INTO execution_artifacts (job_id, attempt_id, kind, value) VALUES (?, ?, 'checkpoint', 'delayed')", [
                $context->jobId,
                $context->attemptId,
            ]);
        });

        return ['accepted' => true];
    }

    /**
     * Retain actual custody bytes and invoke the shared guarded host service.
     */
    private function write(ExecutionContext $context, string $kind, string $value): array
    {
        if ($kind === 'custody') {
            $path = dirname($this->prefix) . '/custody-' . bin2hex(random_bytes(8));
            file_put_contents($path, $value);
            $this->host->custody($context, $value, $path);
        } elseif ($kind === 'checkpoint') {
            $this->host->checkpoint($context, $value);
        } else {
            $this->host->result($context, $value);
        }

        return ['accepted' => true];
    }
}
