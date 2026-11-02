<?php

declare(strict_types=1);

namespace App\Operations\Models;

use App\Identity\Identifier;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\Database\Virtual;

/**
 * Retain each immutable input until that exact snapshot is acknowledged.
 */
#[Table('operation_demands')]
final class Demand
{
    #[Virtual]
    public Identifier $id {
        get => Identifier::fromUuid(static::class, (string) $this->uuid);
    }

    public function __construct(
        public PrimaryKey $uuid,
        public string $assignment_id,
        public string $observation,
        public string $observation_key,
        public string $payload,
        public bool $satisfied = false,
        public ?string $next_id = null,
        public ?string $current_attempt = null,
    ) {}
}
