<?php

declare(strict_types=1);

namespace App\Operations\Models;

use App\Operations\Assignment;
use App\Operations\Authority\PublicationAuthority;
use App\Operations\DTO\AssignmentContext;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;

use function Tempest\Database\query;

/**
 * Coverage adopted by one authoritative Attempt after reconciling retained custody.
 */
#[Table('operation_results')]
final class Result
{
    public function __construct(
        public PrimaryKey $id,
        public string $assignment_id,
        public string $demand_id,
        public string $attempt_id,
        public string $coverage,
    ) {}

    /**
     * Adoption requires this Attempt to reconcile retained custody and rejects conflicting coverage.
     */
    public static function adopt(PublicationAuthority $authority, AssignmentContext $context, string $coverage): void
    {
        $authority->protect($context, function () use ($context, $coverage): void {
            $assignment = $context->assignmentId->uuidFor(Assignment::class);
            $demand = $context->demandId->uuidFor(Demand::class);
            $attempt = $context->attemptId->uuidFor(Attempt::class);
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
