<?php

declare(strict_types=1);

namespace App\Operations;

use App\Persistence\Model;
use App\Persistence\Persistence;
use App\Persistence\Policy;
use JsonSerializable;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\Database\Virtual;

/**
 * Admission identity and outcome; Worker::status supplies the live execution projection.
 */
#[Table('operation_assignments')]
#[Persistence(insert: Policy::Allowed)]
final class Assignment extends Model implements JsonSerializable
{
    /**
     * Populated only by Worker::status(); direct model reads carry persisted facts only.
     */
    #[Virtual]
    public AssignmentState $state;

    /**
     * Retain admission fields and the caller-supplied identity; Worker owns state projection.
     */
    public function __construct(
        PrimaryKey $uuid,
        public string $job_type,
        public string $work_key,
        public Priority $priority,
        public bool $active = true,
        public ?string $error = null,
    ) {
        $this->uuid = $uuid;
    }

    /**
     * Preserve the public status response without exposing internal persistence fields.
     */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'state' => $this->state, 'priority' => $this->priority, 'error' => $this->error];
    }
}
