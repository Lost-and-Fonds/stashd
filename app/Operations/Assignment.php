<?php

declare(strict_types=1);

namespace App\Operations;

use App\Identity\Identifier;
use App\Identity\IdentifierType;
use JsonSerializable;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\Database\Virtual;

/**
 * Admission identity and outcome; Worker::status supplies the live execution projection.
 */
#[Table('operation_assignments')]
final class Assignment implements JsonSerializable
{
    #[Virtual]
    public Identifier $id {
        get => Identifier::fromUuid(IdentifierType::Assignment, (string) $this->uuid);
    }

    #[Virtual]
    private ?AssignmentState $executionState = null;

    // Tempest hydrates without constructors; derive the base state from persisted facts.
    #[Virtual]
    public AssignmentState $state {
        get => $this->executionState ?? ($this->active ? AssignmentState::Queued : ($this->error === null ? AssignmentState::Completed : AssignmentState::Failed));
        set {
            $this->executionState = $value;
        }
    }

    public function __construct(
        public PrimaryKey $uuid,
        public string $job_type,
        public string $work_key,
        public Priority $priority,
        public bool $active = true,
        public ?string $error = null,
    ) {}

    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'state' => $this->state, 'priority' => $this->priority, 'error' => $this->error];
    }
}
