<?php

declare(strict_types=1);

namespace App\Operations\Models;

use App\Operations\AttemptOutcome;
use App\Persistence\Model;
use App\Persistence\Persistence;
use DateTimeImmutable;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;

/**
 * Physical handler history and publication ownership; Durable still owns retries.
 */
#[Table('operation_attempts')]
#[Persistence]
final class Attempt extends Model
{
    /**
     * Retain session ownership history; PublicationAuthority alone creates and settles episodes.
     */
    public function __construct(
        PrimaryKey $uuid,
        public string $assignment_id,
        public string $demand_id,
        public int $backend_pid,
        public string $backend_start,
        public int $lock_key,
        public AttemptOutcome $outcome,
        public DateTimeImmutable $started_at,
        public ?DateTimeImmutable $ended_at = null,
    ) {
        $this->uuid = $uuid;
    }
}
