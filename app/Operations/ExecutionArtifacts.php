<?php

declare(strict_types=1);

namespace App\Operations;

use App\Identity\IdentifierType;
use App\Operations\Authority\PublicationAuthority;
use App\Operations\DTO\AssignmentContext;
use App\Operations\Models\Checkpoint;
use App\Operations\Models\CustodyReceipt;
use App\Operations\Models\Reconciliation;
use App\Operations\Models\Result;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Tempest\Database\PrimaryKey;

use function Tempest\Database\query;

/**
 * Callers retain immutable staged files; SQL acknowledges evidence, not Vault publication.
 * Each adopting Attempt must independently verify those bytes before publishing results.
 */
final readonly class ExecutionArtifacts
{
    public function __construct(
        private PublicationAuthority $authority,
    ) {}

    public function checkpoint(AssignmentContext $context, string $value): void
    {
        $this->authority->protect($context, function () use ($context, $value): void {
            $checkpoint = new Checkpoint(
                new PrimaryKey((string) Uuid::v7()),
                $context->assignmentId->uuidFor(IdentifierType::Assignment),
                $context->demandId->uuidFor(IdentifierType::Demand),
                $context->attemptId->uuidFor(IdentifierType::Attempt),
                $value,
            );
            query($checkpoint)->insert($checkpoint)->execute();
        });
    }

    // Hash outside the transaction; the caller must keep acknowledged bytes immutable.
    public function custody(AssignmentContext $context, string $key, string $path): void
    {
        $digest = is_file($path) ? hash_file('sha256', $path) : false;
        if ($digest === false) {
            throw new RuntimeException('Custody bytes are unavailable.');
        }

        $this->authority->protect($context, function () use ($context, $key, $path, $digest): void {
            $assignment = $context->assignmentId->uuidFor(IdentifierType::Assignment);
            $existing = query(CustodyReceipt::class)->select()->where('assignment_id', $assignment)->where('key', $key)->first();
            if ($existing !== null) {
                assert($existing instanceof CustodyReceipt, 'Tempest must hydrate the requested domain entity.');
                if ($existing->digest !== $digest || $existing->path !== $path) {
                    throw new RuntimeException('Custody key already acknowledges different retained bytes.');
                }

                return;
            }

            $receipt = new CustodyReceipt(
                new PrimaryKey((string) Uuid::v7()),
                $assignment,
                $context->demandId->uuidFor(IdentifierType::Demand),
                $context->attemptId->uuidFor(IdentifierType::Attempt),
                $key,
                $path,
                $digest,
            );
            query($receipt)->insert($receipt)->execute();
        });
    }

    /** @return list<string> Verified custody keys. */
    public function reconcile(AssignmentContext $context): array
    {
        /** @var list<CustodyReceipt> $receipts */
        $receipts = query(CustodyReceipt::class)->select()->where('assignment_id', $context->assignmentId->uuidFor(IdentifierType::Assignment))->all();
        foreach ($receipts as $receipt) {
            if (! is_file($receipt->path) || hash_file('sha256', $receipt->path) !== $receipt->digest) {
                throw new RuntimeException('Retained custody failed verification.');
            }
        }

        return $this->authority->protect($context, function () use ($context, $receipts): array {
            foreach ($receipts as $receipt) {
                $attempt = $context->attemptId->uuidFor(IdentifierType::Attempt);
                if (
                    query(Reconciliation::class)
                        ->select()
                        ->where('attempt_id', $attempt)
                        ->where('receipt_id', (string) $receipt->id)
                        ->first() !== null
                ) {
                    continue;
                }

                $record = new Reconciliation(new PrimaryKey((string) Uuid::v7()), $attempt, (string) $receipt->id);
                query($record)->insert($record)->execute();
            }

            return array_map(static fn (CustodyReceipt $receipt): string => $receipt->key, $receipts);
        });
    }

    public function result(AssignmentContext $context, string $coverage): void
    {
        $this->authority->protect($context, function () use ($context, $coverage): void {
            $assignment = $context->assignmentId->uuidFor(IdentifierType::Assignment);
            $demand = $context->demandId->uuidFor(IdentifierType::Demand);
            $attempt = $context->attemptId->uuidFor(IdentifierType::Attempt);
            /** @var list<CustodyReceipt> $receipts */
            $receipts = query(CustodyReceipt::class)->select()->where('assignment_id', $assignment)->all();
            foreach ($receipts as $receipt) {
                if (
                    query(Reconciliation::class)
                        ->select()
                        ->where('attempt_id', $attempt)
                        ->where('receipt_id', (string) $receipt->id)
                        ->first() === null
                ) {
                    throw new RuntimeException('Retained custody requires reconciliation.');
                }
            }

            $existing = query(Result::class)->select()->where('demand_id', $demand)->first();
            if ($existing === null) {
                $result = new Result(new PrimaryKey((string) Uuid::v7()), $assignment, $demand, $attempt, $coverage);
                query($result)->insert($result)->execute();
            } else {
                assert($existing instanceof Result, 'Tempest must hydrate the requested domain entity.');
                if ($existing->coverage !== $coverage) {
                    throw new RuntimeException('A different result was already adopted.');
                }
            }
        });
    }
}
