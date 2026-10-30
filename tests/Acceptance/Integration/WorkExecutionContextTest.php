<?php

declare(strict_types=1);

use App\Integration\OperationsConfig;
use App\Operations\DTO\ExecutionContext;
use App\Operations\JobRuntime;
use App\Operations\Jobs;
use App\Operations\Work;
use App\Operations\WorkExecutor;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

require_once dirname(__DIR__, 2) . '/Support/Integration/configuration-cli.php';

test('executors receive the persisted claim metadata for each Attempt of one Job', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        $database->exec(
            'CREATE TABLE configuration_proof.context_observations (job_id UUID, attempt_id UUID, generation INTEGER, lease_expires_at TIMESTAMPTZ, claim_token TEXT, persisted_generation INTEGER, persisted_lease TIMESTAMPTZ)',
        );
        /**
         * Persist the domain probe's observations independently of runtime writes.
         */
        $executor = new class($database) implements WorkExecutor {
            /**
             * Record executor-visible metadata through an independent connection.
             */
            public function __construct(
                private PDO $database,
            ) {}

            /**
             * Observe each real execution and fail the first to exercise a retry.
             */
            public function execute(Work $work, ExecutionContext $context): void
            {
                $statement = $this->database->prepare(
                    'INSERT INTO configuration_proof.context_observations SELECT id, ?::uuid, ?::integer, ?::timestamptz, claim_token, generation, lease_until FROM configuration_proof.jobs WHERE id = ? AND work_type = ?',
                );
                $statement->execute([$context->attemptId, $context->generation, $context->leaseExpiresAt->format('Y-m-d H:i:s.uP'), $context->jobId, $work->type]);
                if ($context->generation === 0) {
                    throw new RuntimeException('Retry the context probe.');
                }
            }
        };
        $runtime = new JobRuntime(new OperationsConfig($descriptor['dsn'], $descriptor['username'], $descriptor['password'], 'configuration_proof'), $executor);
        $id = new Jobs($runtime)->request(new Work('acceptance.context', 'scope-1', 'refresh', 'revision-1'));
        $runtime->work('outbox', true);
        $runtime->work('jobs', true);
        $database->exec("UPDATE configuration_proof.messenger_messages SET available_at = now() - interval '1 minute' WHERE queue_name = 'outbox'");
        $runtime->work('outbox', true);
        $runtime->work('jobs', true);

        $observations = $database
            ->query(
                'SELECT job_id, attempt_id, generation, attempt_id::text = claim_token AND generation = persisted_generation AND lease_expires_at = persisted_lease AS matches_claim FROM configuration_proof.context_observations ORDER BY generation',
            )
            ->fetchAll(PDO::FETCH_ASSOC);
        $attempts = $database->query('SELECT id, outcome FROM configuration_proof.job_attempts ORDER BY started_at')->fetchAll(PDO::FETCH_ASSOC);
        expect($observations)
            ->toHaveCount(2)
            ->and(array_column($observations, 'job_id'))
            ->toBe([$id, $id])
            ->and(array_column($observations, 'generation'))
            ->toBe([0, 1])
            ->and(array_column($observations, 'matches_claim'))
            ->toBe([true, true])
            ->and(array_column($observations, 'attempt_id'))
            ->toBe(array_column($attempts, 'id'))
            ->and($observations[0]['attempt_id'])
            ->not
            ->toBe($observations[1]['attempt_id'])
            ->and(array_column($attempts, 'outcome'))
            ->toBe(['failed', 'completed'])
            ->and($database->query('SELECT status FROM configuration_proof.jobs')->fetchColumn())
            ->toBe('completed');
    });
});
