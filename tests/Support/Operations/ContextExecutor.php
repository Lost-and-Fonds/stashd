<?php

declare(strict_types=1);

namespace Tests\Support\Operations;

use App\Operations\DTO\ExecutionContext;
use App\Operations\Work;
use App\Operations\WorkExecutor;
use PDO;
use RuntimeException;

/**
 * Observe executor metadata independently and force one real domain retry.
 */
final readonly class ContextExecutor implements WorkExecutor
{
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
            'INSERT INTO context_observations SELECT id, ?::uuid, ?::integer, ?::timestamptz, claim_token, generation, lease_until FROM jobs WHERE id = ? AND work_type = ?',
        );
        $statement->execute([$context->attemptId, $context->generation, $context->leaseExpiresAt->format('Y-m-d H:i:s.uP'), $context->jobId, $work->type]);
        if ($context->generation === 0) {
            throw new RuntimeException('Retry the context probe.');
        }
    }
}
