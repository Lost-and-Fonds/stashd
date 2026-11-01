<?php

declare(strict_types=1);

namespace App\Operations;

use App\Identity\Identifier;
use App\Identity\IdentifierType;
use App\Operations\Authority\SessionActivityClaims;
use App\Operations\DTO\ActivityOwnership;
use App\Operations\Durable\DurableRuntime;
use App\Operations\Durable\JobRegistry;
use App\Operations\Durable\StartAssignment;
use App\Operations\Models\Attempt;
use App\Operations\Models\Demand;
use App\Operations\Persistence\AdmissionLock;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\ExecutionId;
use InvalidArgumentException;
use Tempest\Container\Singleton;
use Tempest\Database\PrimaryKey;
use Tempest\Validation\Rules\HasLength;

use function Tempest\Database\query;
use function Tempest\Mapper\map;

/**
 * Admit departmental requests without exposing workflow or delivery machinery.
 */
#[Singleton]
final readonly class Worker
{
    public function __construct(
        private AdmissionLock $admission,
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
        $id = $this->admission->database->transactional(function () use ($priority, $type, $key, $observation, $payload): Identifier {
            $this->admission->lock($type, $key);
            $dispatch = false;
            /** @var Assignment|null $assignment */
            $assignment = query(Assignment::class)->select()->where('job_type', $type)->where('work_key', $key)->where('active', true)->first();
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
                query($assignment)->insert($assignment)->execute();
            } elseif ($priority === Priority::Interactive && $assignment->priority === Priority::Routine) {
                $dispatch = true;
                $assignment->priority = $priority;
                query(Assignment::class)
                    ->update(priority: $assignment->priority, active: $assignment->active, error: $assignment->error)
                    ->where('uuid', (string) $assignment->uuid)
                    ->execute();
                $this->execution->recover((string) $assignment->uuid);
            }

            $uuid = (string) $assignment->uuid;
            $observationKey = hash('sha256', $observation);
            /** @var Demand|null $existing */
            $existing = query(Demand::class)->select()->where('assignment_id', $uuid)->where('observation_key', $observationKey)->first();
            if ($existing === null) {
                $demand = new Demand(
                    new PrimaryKey(Identifier::create(IdentifierType::Demand)->uuidFor(IdentifierType::Demand)),
                    $uuid,
                    $observation,
                    $observationKey,
                    $payload,
                );
                query($demand)->insert($demand)->execute();
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
        /** @var Assignment $assignment */
        $assignment = query(Assignment::class)->select()->where('uuid', $uuid)->first() ?? throw new InvalidArgumentException('Assignment not found.');
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

        /** @var Demand $demand */
        foreach (query(Demand::class)->select()->where('assignment_id', $uuid)->where('satisfied', false)->all() as $demand) {
            if ($demand->current_attempt === null) {
                continue;
            }

            /** @var Attempt|null $attempt */
            $attempt = query(Attempt::class)->select()->where('uuid', $demand->current_attempt)->where('outcome', AttemptOutcome::Running)->where('ended_at IS NULL')->first();
            if (
                $attempt !== null
                && SessionActivityClaims::owns($this->admission->database, new ActivityOwnership($attempt->backend_pid, $attempt->backend_start, $attempt->lock_key))
            ) {
                $assignment->state = AssignmentState::Running;
                break;
            }
        }

        return $assignment;
    }

    public function recover(Identifier $id): int
    {
        return $this->execution->recover($id->uuidFor(IdentifierType::Assignment));
    }
}
