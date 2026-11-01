<?php

declare(strict_types=1);

namespace App\Operations;

use App\Identity\Identifier;
use App\Identity\IdentifierType;
use App\Operations\Durable\DurableRuntime;
use App\Operations\Durable\JobRegistry;
use App\Operations\Durable\StartAssignment;
use App\Operations\Models\Demand;
use App\Operations\Persistence\Assignments;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\ExecutionId;
use InvalidArgumentException;
use Tempest\Container\Singleton;
use Tempest\Database\PrimaryKey;
use Tempest\Validation\Rules\HasLength;

use function Tempest\Mapper\map;

/**
 * Admit departmental requests without exposing workflow or delivery machinery.
 */
#[Singleton]
final readonly class Worker
{
    public function __construct(
        private Assignments $assignments,
        private DurableRuntime $runtime,
        private JobRegistry $registry,
        private AssignmentExecution $execution,
    ) {}

    /**
     * Join equivalent active work and retain its input before returning the durable identity.
     * Reusing an observation for different input rejects the whole admission transaction.
     */
    public function do(Job $job, Priority $priority = Priority::Routine): Assignment
    {
        $type = $this->registry->type($job);
        $key = $job->key();
        $observation = $job->observation();
        foreach ([$key, $observation] as $value) {
            if (! new HasLength(min: 1, max: 1024)->isValid($value) || str_contains($value, "\0")) {
                throw new InvalidArgumentException('Job keys and observations require 1 to 1024 characters without null bytes.');
            }
        }

        $payload = map($job)->toJson();
        $id = $this->assignments->database->transactional(function () use ($priority, $type, $key, $observation, $payload): Identifier {
            $this->assignments->lock($type, $key);
            $dispatch = false;
            $assignment = $this->assignments->active($type, $key);
            if ($assignment !== null) {
                // Only admission reconciles terminal failure; status reads never write.
                $this->execution->reconcileFailure($assignment);
                if (! $assignment->active) {
                    $assignment = null;
                }
            }

            if ($assignment === null) {
                $dispatch = true;
                $id = Identifier::create(IdentifierType::Assignment);
                $assignment = new Assignment(new PrimaryKey($id->uuidFor(IdentifierType::Assignment)), $type, $key, $priority);
                $this->assignments->insert($assignment);
            } elseif ($priority === Priority::Interactive && $assignment->priority === Priority::Routine) {
                $dispatch = true;
                $assignment->priority = $priority;
                $this->assignments->update($assignment);
                $this->execution->recover((string) $assignment->uuid);
            }

            $uuid = (string) $assignment->uuid;
            $observationKey = hash('sha256', $observation);
            $existing = $this->assignments->observed($uuid, $observationKey);
            if ($existing === null) {
                $this->assignments->insert(new Demand(
                    new PrimaryKey(Identifier::create(IdentifierType::Demand)->uuidFor(IdentifierType::Demand)),
                    $uuid,
                    $observation,
                    $observationKey,
                    $payload,
                ));
            } elseif ($existing->observation !== $observation || $existing->payload !== $payload) {
                throw new InvalidArgumentException('An observation already identifies different Job input.');
            }

            if ($dispatch) {
                $this->runtime->admit(new StartAssignment($uuid), $assignment->priority);
            }

            return $assignment->id;
        });

        return $this->status($id);
    }

    /**
     * Read the entity with current execution facts; never reconcile persistence here.
     */
    public function status(Identifier $id): Assignment
    {
        $uuid = $id->uuidFor(IdentifierType::Assignment);
        $assignment = $this->assignments->find($uuid) ?? throw new InvalidArgumentException('Assignment not found.');
        if (! $assignment->active) {
            return $assignment;
        }

        foreach ($this->runtime->events->readStream(ExecutionId::fromString($uuid)) as $event) {
            if (! $event instanceof WorkflowExecutionFailed) {
                continue;
            }

            $assignment->state = AssignmentState::Failed;
            $assignment->error = $event->failureMessage();

            return $assignment;
        }

        // An old start event says nothing about a later, still queued demand.
        $running = $this->assignments->database->fetchOne(
            "SELECT EXISTS(SELECT 1 FROM operation_demands d JOIN operation_attempts a ON a.uuid = d.current_attempt JOIN pg_stat_activity s ON s.pid = a.backend_pid AND s.backend_start::text = a.backend_start WHERE d.assignment_id = ? AND NOT d.satisfied AND a.outcome = 'running' AND a.ended_at IS NULL)",
            [$uuid],
        );
        if ($running) {
            $assignment->state = AssignmentState::Running;
        }

        return $assignment;
    }

    public function recover(Identifier $id): int
    {
        return $this->execution->recover($id->uuidFor(IdentifierType::Assignment));
    }
}
