<?php

declare(strict_types=1);

namespace App\Operations\Models;

use App\Operations\Assignment;
use App\Operations\Authority\PublicationAuthority;
use App\Operations\DTO\AssignmentContext;
use App\Persistence\Model;
use App\Persistence\Persistence;
use App\Persistence\Policy;
use RuntimeException;
use Tempest\Database\Table;

use function Tempest\Database\query;

/**
 * Coverage recorded by one authoritative Attempt after reconciling retained custody.
 */
#[Table('operation_results')]
#[Persistence(insert: Policy::Fenced)]
final class Result extends Model
{
    /**
     * Retain the execution that verified this coverage; shared persistence owns identity and timestamps.
     */
    public function __construct(
        public string $assignment_id,
        public string $demand_id,
        public string $attempt_id,
        public string $coverage,
    ) {}

    /**
     * Recording requires this Attempt to reconcile retained custody and rejects conflicting coverage.
     * It does not satisfy the Demand or complete its Assignment.
     */
    public static function record(PublicationAuthority $authority, AssignmentContext $context, string $coverage): void
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
                $result = new Result($assignment, $demand, $attempt, $coverage);
                $result->insert();
            } else {
                assert($existing instanceof Result, 'Tempest must hydrate the requested domain entity.');
                if ($existing->coverage !== $coverage) {
                    throw new RuntimeException('A different result was already recorded.');
                }
            }
        });
    }
}
