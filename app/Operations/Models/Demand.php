<?php

declare(strict_types=1);

namespace App\Operations\Models;

use App\Persistence\Model;
use App\Persistence\Persistence;
use App\Persistence\Policy;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;

/**
 * Retain each immutable input until that exact snapshot is acknowledged.
 */
#[Table('operation_demands')]
#[Persistence(insert: Policy::Allowed)]
final class Demand extends Model
{
    /**
     * Retain immutable input; service transactions own satisfaction and Attempt selection.
     */
    public function __construct(
        PrimaryKey $uuid,
        public string $assignment_id,
        public string $observation,
        public string $observation_key,
        public string $payload,
        public bool $satisfied = false,
        public ?string $next_id = null,
        public ?string $current_attempt = null,
    ) {
        $this->uuid = $uuid;
    }
}
