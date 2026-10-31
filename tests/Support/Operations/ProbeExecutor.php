<?php

declare(strict_types=1);

namespace Tests\Support\Operations;

use App\Operations\DTO\ExecutionContext;
use App\Operations\Work;
use App\Operations\WorkExecutor;
use PDO;
use RuntimeException;

/**
 * Persist each real probe execution independently of Operations Job status.
 *
 * The effect table deliberately has no Job uniqueness rule to hide duplicates.
 */
final readonly class ProbeExecutor implements WorkExecutor
{
    /**
     * Keep an independent connection belonging only to the disposable fixture.
     */
    public function __construct(
        private PDO $database,
    ) {}

    /**
     * Observe the operation before recording an effect on a separate connection.
     */
    public function execute(Work $work, ExecutionContext $context): void
    {
        $scope = $this->database->prepare('SELECT id FROM probe_scopes WHERE id = ?');
        $scope->execute([$work->scope]);
        if ($work->type !== 'acceptance.probe' || ! in_array($work->mode, ['refresh', 'complete'], true) || $scope->fetchColumn() === false) {
            throw new RuntimeException('The disposable probe does not recognize this Work.');
        }

        $effect = $this->database->prepare('INSERT INTO probe_effects (scope_id, mode, job_id, observation) VALUES (?, ?, ?, ?)');
        $effect->execute([$work->scope, $work->mode, $context->jobId, $work->observation]);
    }
}
