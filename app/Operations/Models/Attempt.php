<?php

declare(strict_types=1);

namespace App\Operations\Models;

use App\Identity\Identifier;
use App\Identity\IdentifierType;
use App\Operations\AttemptOutcome;
use DateTimeImmutable;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\Database\Virtual;

/**
 * Physical handler history and publication ownership; Durable still owns retries.
 */
#[Table('operation_attempts')]
final class Attempt
{
    #[Virtual]
    public Identifier $id {
        get => Identifier::fromUuid(IdentifierType::Attempt, (string) $this->uuid);
    }

    public function __construct(
        public PrimaryKey $uuid,
        public string $assignment_id,
        public string $demand_id,
        public int $backend_pid,
        public string $backend_start,
        public AttemptOutcome $outcome,
        public DateTimeImmutable $started_at,
        public ?DateTimeImmutable $ended_at = null,
    ) {}
}
