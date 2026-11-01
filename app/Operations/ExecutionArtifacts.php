<?php

declare(strict_types=1);

namespace App\Operations;

use App\Operations\Authority\PublicationAuthority;
use App\Operations\DTO\AssignmentContext;
use App\Operations\Models\ExecutionArtifact;
use App\Operations\Models\Reconciliation;
use Gplanchat\Durable\ExecutionId;
use RuntimeException;
use Tempest\Database\PrimaryKey;

use function Tempest\Database\query;

/**
 * Protect bounded custody and result handoff without pretending SQL publishes Vault files.
 *
 * Callers stage and retain immutable files. A receipt acknowledges their digest;
 * every adopting Attempt must independently verify them before publishing results.
 */
final readonly class ExecutionArtifacts
{
    /**
     * Route every authoritative database mutation through the current Activity fence.
     */
    public function __construct(
        private PublicationAuthority $authority,
    ) {}

    /**
     * Append a domain checkpoint without granting publication rights to its context.
     */
    public function checkpoint(AssignmentContext $context, string $value): void
    {
        $this->authority->protect($context, fn () => $this->insert($context, ArtifactKind::Checkpoint, $value));
    }

    /**
     * Acknowledge caller-retained immutable bytes after hashing outside the transaction.
     *
     * This performs no filesystem publication. The caller must retain these bytes
     * beyond the worker lifetime; later reconciliation rejects changed or lost files.
     */
    public function custody(AssignmentContext $context, string $key, string $path): string
    {
        $digest = is_file($path) ? hash_file('sha256', $path) : false;
        if ($digest === false) {
            throw new RuntimeException('Custody bytes are unavailable.');
        }

        return $this->authority->protect($context, function () use ($context, $key, $path, $digest): string {
            /** @var ExecutionArtifact|null $existing */
            $existing = query(ExecutionArtifact::class)
                ->select()
                ->where('assignment_id', $context->assignmentId)
                ->where('kind', ArtifactKind::Custody->value)
                ->where('value', $key)
                ->first();
            if ($existing !== null) {
                if ($existing->digest !== $digest || $existing->path !== $path) {
                    throw new RuntimeException('Custody key already acknowledges different retained bytes.');
                }

                return (string) $existing->id;
            }

            return $this->insert($context, ArtifactKind::Custody, $key, $path, $digest);
        });
    }

    /**
     * Verify all acknowledged custody and durably record this Attempt's reconciliation.
     *
     * @return list<string> Verified receipt keys.
     */
    public function reconcile(AssignmentContext $context): array
    {
        /** @var list<ExecutionArtifact> $receipts */
        $receipts = query(ExecutionArtifact::class)->select()->where('assignment_id', $context->assignmentId)->where('kind', ArtifactKind::Custody->value)->all();
        foreach ($receipts as $receipt) {
            if ($receipt->path === null || ! is_file($receipt->path) || hash_file('sha256', $receipt->path) !== $receipt->digest) {
                throw new RuntimeException('Retained custody failed verification.');
            }
        }

        return $this->authority->protect($context, function () use ($context, $receipts): array {
            foreach ($receipts as $receipt) {
                if (
                    query(Reconciliation::class)
                        ->select()
                        ->where('attempt_id', $context->attemptId)
                        ->where('artifact_id', (string) $receipt->id)
                        ->first() !== null
                ) {
                    continue;
                }

                $record = new Reconciliation();
                $record->id = new PrimaryKey((string) ExecutionId::generate());
                $record->attempt_id = $context->attemptId;
                $record->artifact_id = (string) $receipt->id;
                query($record)->insert($record)->execute();
            }

            return array_map(static fn (ExecutionArtifact $receipt): string => $receipt->value, $receipts);
        });
    }

    /**
     * Adopt a result only after this Attempt reconciled every retained custody receipt.
     */
    public function result(AssignmentContext $context, string $coverage): void
    {
        $this->authority->protect($context, function () use ($context, $coverage): void {
            /** @var list<ExecutionArtifact> $receipts */
            $receipts = query(ExecutionArtifact::class)->select()->where('assignment_id', $context->assignmentId)->where('kind', ArtifactKind::Custody->value)->all();
            foreach ($receipts as $receipt) {
                if (
                    query(Reconciliation::class)
                        ->select()
                        ->where('attempt_id', $context->attemptId)
                        ->where('artifact_id', (string) $receipt->id)
                        ->first() === null
                ) {
                    throw new RuntimeException('Retained custody requires reconciliation.');
                }
            }

            /** @var ExecutionArtifact|null $existing */
            $existing = query(ExecutionArtifact::class)->select()->where('demand_id', $context->demandId)->where('kind', ArtifactKind::Result->value)->first();
            if ($existing === null) {
                $this->insert($context, ArtifactKind::Result, $coverage);
            } elseif ($existing->value !== $coverage) {
                throw new RuntimeException('A different result was already adopted.');
            }
        });
    }

    /**
     * Persist evidence through a Tempest model inside the caller's protected transaction.
     */
    private function insert(AssignmentContext $context, ArtifactKind $kind, string $value, ?string $path = null, ?string $digest = null): string
    {
        $artifact = new ExecutionArtifact();
        $artifact->id = new PrimaryKey((string) ExecutionId::generate());
        $artifact->assignment_id = $context->assignmentId;
        $artifact->demand_id = $context->demandId;
        $artifact->attempt_id = $context->attemptId;
        $artifact->kind = $kind;
        $artifact->value = $value;
        $artifact->path = $path;
        $artifact->digest = $digest;
        query($artifact)->insert($artifact)->execute();

        return (string) $artifact->id;
    }
}
