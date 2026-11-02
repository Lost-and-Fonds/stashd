<?php

declare(strict_types=1);

namespace App\Operations\Models;

use App\Identity\IdentifierType;
use App\Operations\Authority\PublicationAuthority;
use App\Operations\DTO\AssignmentContext;
use App\Persistence\Model;
use App\Persistence\Persistence;
use App\Persistence\Policy;
use Tempest\Database\Table;

/**
 * A protected progress marker, retained with the Attempt that published it.
 */
#[Table('operation_checkpoints')]
#[Persistence(insert: Policy::Fenced)]
final class Checkpoint extends Model
{
    public const IdentifierType IDENTIFIER_TYPE = IdentifierType::Checkpoint;

    /**
     * Retain the execution that published this progress value.
     */
    public function __construct(
        public string $assignment_id,
        public string $demand_id,
        public string $attempt_id,
        public string $value,
    ) {}

    /**
     * Progress is recorded only while this Attempt still owns publication authority.
     */
    public static function record(PublicationAuthority $authority, AssignmentContext $context, string $value): void
    {
        $authority->protect($context, function () use ($context, $value): void {
            $checkpoint = new Checkpoint(
                $context->assignmentId->uuidFor(IdentifierType::Assignment),
                $context->demandId->uuidFor(IdentifierType::Demand),
                $context->attemptId->uuidFor(IdentifierType::Attempt),
                $value,
            );
            $checkpoint->insert();
        });
    }
}
