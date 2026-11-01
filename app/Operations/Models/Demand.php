<?php

declare(strict_types=1);

namespace App\Operations\Models;

use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;

/**
 * Retain an observed input until a handler acknowledges that exact snapshot.
 *
 * New demand never replaces the input of an activity already executing.
 */
#[Table('operation_demands')]
final class Demand
{
    public PrimaryKey $id;
    public string $assignment_id;
    public string $observation;
    public string $observation_key;
    public string $job_class;
    public string $payload;
    public bool $satisfied = false;
    public ?string $next_id = null;
    public ?string $current_attempt = null;
}
