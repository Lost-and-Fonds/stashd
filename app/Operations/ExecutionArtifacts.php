<?php

declare(strict_types=1);

namespace App\Operations;

use App\Operations\DTO\ExecutionContext;
use Doctrine\DBAL\Connection;

/**
 * Retain bounded execution evidence and require verified custody before adoption.
 *
 * This handoff is not an OCFL journal. Callers own staging and retaining immutable
 * files; receipts record their digest. Remote effects still need reconciliation.
 */
final readonly class ExecutionArtifacts
{
    private Connection $database;

    /**
     * Route every authoritative write through Operations' common fence.
     */
    public function __construct(
        private AttemptAuthority $authority,
        \Tempest\Database\Connection\Connection $connection,
    ) {
        if (! $connection instanceof \Ecotone\Api\Tempest\DbalBackedConnection) {
            throw new \LogicException('Execution artifacts require the shared database connection.');
        }

        $this->database = $connection->database();
    }

    /**
     * Append a checkpoint without changing the logical Work or lease.
     */
    public function checkpoint(ExecutionContext $context, string $value): void
    {
        $this->authority->protect($context, fn (Connection $db) => $db->executeStatement("INSERT INTO execution_artifacts (job_id, attempt_id, kind, value) VALUES (?, ?, 'checkpoint', ?)", [
            $context->jobId,
            $context->attemptId,
            $value,
        ]));
    }

    /**
     * Acknowledge retained bytes after independently hashing their durable file.
     *
     * The caller must retain this immutable file beyond the executor's lifetime.
     */
    public function custody(ExecutionContext $context, string $key, string $path): void
    {
        $digest = hash_file('sha256', $path);
        if ($digest === false) {
            throw new \RuntimeException('Custody bytes are unavailable.');
        }

        $this->authority->protect($context, fn (Connection $db) => $db->executeStatement("INSERT INTO execution_artifacts (job_id, attempt_id, kind, value, path, digest) VALUES (?, ?, 'custody', ?, ?, ?)", [
            $context->jobId,
            $context->attemptId,
            $key,
            $path,
            $digest,
        ]));
    }

    /**
     * Verify retained bytes and record which receipts this Attempt reconciled.
     *
     * @return list<string>
     */
    public function reconcile(ExecutionContext $context): array
    {
        // Hash retained bytes before locking the Job; files are caller-owned and immutable.
        $rows = $this->database->fetchAllAssociative("SELECT id, value, path, digest FROM execution_artifacts WHERE job_id = ? AND kind = 'custody' ORDER BY value, id", [
            $context->jobId,
        ]);
        foreach ($rows as $row) {
            if (! is_file($row['path']) || hash_file('sha256', $row['path']) !== $row['digest']) {
                throw new \RuntimeException('Retained custody failed verification.');
            }
        }

        return $this->authority->protect($context, function (Connection $db) use ($context, $rows): array {
            foreach ($rows as $row) {
                $db->executeStatement('INSERT INTO execution_reconciliations (attempt_id, artifact_id) VALUES (?, ?) ON CONFLICT DO NOTHING', [$context->attemptId, $row['id']]);
            }

            return array_map(static fn (array $row): string => (string) $row['value'], $rows);
        });
    }

    /**
     * Adopt coverage only after this Attempt verified every retained custody receipt.
     *
     * The Job lock serializes replay: a prior adoption returns without another row.
     * Preservation remains responsible for deciding whether coverage is archival success.
     */
    public function result(ExecutionContext $context, string $coverage): void
    {
        $this->authority->protect($context, function (Connection $db) use ($context, $coverage): void {
            if ($db->fetchOne(
                "SELECT EXISTS (SELECT 1 FROM execution_artifacts a WHERE a.job_id = ? AND a.kind = 'custody' AND NOT EXISTS (SELECT 1 FROM execution_reconciliations r WHERE r.artifact_id = a.id AND r.attempt_id = ?))",
                [$context->jobId, $context->attemptId],
            )) {
                throw new \RuntimeException('Retained custody requires reconciliation.');
            }

            if (! $db->fetchOne("SELECT EXISTS (SELECT 1 FROM execution_artifacts WHERE job_id = ? AND kind = 'result')", [$context->jobId])) {
                $db->executeStatement("INSERT INTO execution_artifacts (job_id, attempt_id, kind, value) VALUES (?, ?, 'result', ?)", [
                    $context->jobId,
                    $context->attemptId,
                    $coverage,
                ]);
            }
        });
    }
}
