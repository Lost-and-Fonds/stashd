<?php

declare(strict_types=1);

namespace App\Operations\Models;

use App\Operations\AttemptOutcome;
use DateTimeImmutable;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;

/**
 * Retain physical domain execution episodes and their publication authority token.
 *
 * This is domain handoff history, not a second workflow scheduler or retry budget.
 */
#[Table('operation_attempts')]
final class AttemptRecord
{
    public PrimaryKey $id;
    public string $assignment_id;
    public string $demand_id;
    public int $backend_pid;
    public string $backend_start;
    public AttemptOutcome $outcome;
    public DateTimeImmutable $started_at;
    public ?DateTimeImmutable $ended_at = null;
}
