<?php

declare(strict_types=1);

namespace App\Operations\Models;

use App\Identity\IdentifierType;
use App\Operations\Authority\PublicationAuthority;
use App\Operations\DTO\AssignmentContext;
use Symfony\Component\Uid\Uuid;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;

use function Tempest\Database\query;

/**
 * A protected progress marker, retained with the Attempt that published it.
 */
#[Table('operation_checkpoints')]
final class Checkpoint
{
    public function __construct(
        public PrimaryKey $id,
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
                new PrimaryKey((string) Uuid::v7()),
                $context->assignmentId->uuidFor(IdentifierType::Assignment),
                $context->demandId->uuidFor(IdentifierType::Demand),
                $context->attemptId->uuidFor(IdentifierType::Attempt),
                $value,
            );
            query($checkpoint)->insert($checkpoint)->execute();
        });
    }
}
