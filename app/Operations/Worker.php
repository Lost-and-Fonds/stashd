<?php

declare(strict_types=1);

namespace App\Operations;

use App\Operations\Durable\AssignmentWorkflow;
use App\Operations\Durable\DurableRuntime;
use App\Operations\Durable\JobRegistry;
use App\Operations\Durable\StartAssignment;
use App\Operations\Models\AssignmentRecord;
use App\Operations\Models\Demand;
use App\Operations\Persistence\Assignments;
use Doctrine\DBAL\Connection;
use Gplanchat\Durable\Event\ActivityTaskStarted;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\ExecutionId;
use InvalidArgumentException;
use RuntimeException;
use Tempest\Container\Container;
use Tempest\Container\Singleton;
use Tempest\Database\PrimaryKey;

use function Tempest\Mapper\map;

/**
 * Admit typed departmental Jobs and observe their durable Assignments.
 *
 * Durable executes each retained snapshot; this service owns Work identity and
 * satisfaction only, without recreating workflow replay or retry scheduling.
 */
#[Singleton]
final readonly class Worker
{
    /**
     * Compose admission and upstream execution through the application's container.
     */
    public function __construct(
        private Assignments $assignments,
        private DurableRuntime $runtime,
        private JobRegistry $registry,
        private Container $container,
        private \App\Operations\Authority\PublicationAuthority $authority,
    ) {}

    /**
     * Coalesce active Work and durably retain distinct observations before returning.
     */
    public function do(Job $job, Priority $priority = Priority::Routine): Assignment
    {
        $this->registry->handler($job::class);
        $work = $job->work();
        $id = $this->assignments->database->transactional(function (Connection $database) use ($job, $priority, $work): string {
            $this->assignments->lock($work);
            $dispatch = false;
            $record = $this->assignments->active($work);
            if ($record !== null) {
                $this->refreshFailure($record);
                if (! $record->active) {
                    $record = null;
                }
            }

            if ($record === null) {
                $dispatch = true;
                $record = new AssignmentRecord();
                $record->id = new PrimaryKey((string) ExecutionId::generate());
                $record->work_type = $work->type;
                $record->work_scope = $work->scope;
                $record->work_mode = $work->mode;
                $record->priority = $priority;
                $this->assignments->insert($record);
            } elseif ($priority === Priority::Interactive && $record->priority === Priority::Routine) {
                $dispatch = true;
                $record->priority = $priority;
                $this->assignments->save($record);
                $this->recover((string) $record->id);
            }

            $id = (string) $record->id;
            $key = hash('sha256', $work->observation);
            if (! $this->assignments->observed($id, $key)) {
                $demand = new Demand();
                $demand->id = new PrimaryKey((string) ExecutionId::generate());
                $demand->assignment_id = $id;
                $demand->observation = $work->observation;
                $demand->observation_key = $key;
                $demand->job_class = $job::class;
                $demand->payload = map($job)->toJson();
                $this->assignments->insert($demand);
            }

            if ($dispatch) {
                $this->runtime->admit(new StartAssignment($id), $record->priority);
            }

            return $id;
        });

        return $this->find($id);
    }

    /**
     * Refresh an immutable Assignment snapshot from domain records and journal facts.
     */
    public function find(string $id): Assignment
    {
        $record = $this->assignments->find($id) ?? throw new InvalidArgumentException('Assignment not found.');
        $this->refreshFailure($record);
        $state = $record->active ? AssignmentState::Queued : ($record->error === null ? AssignmentState::Completed : AssignmentState::Failed);
        if ($record->active) {
            foreach ($this->runtime->events->readStream(ExecutionId::fromString($id)) as $event) {
                if (! $event instanceof ActivityTaskStarted) {
                    continue;
                }

                $state = AssignmentState::Running;
            }
        }

        return new Assignment($id, $state, $record->priority, $record->error);
    }

    /**
     * Reissue journalled schedules that never started, without replaying side effects.
     *
     * Duplicate invitations are safe through Durable's claim and settled guards.
     * This deliberately does not retry failed or already-started activities.
     */
    public function recover(string $id): int
    {
        $record = $this->assignments->find($id);
        if ($record === null || ! $record->active) {
            return 0;
        }

        $execution = ExecutionId::fromString($id);
        $count = 0;
        foreach ($this->runtime->events->readStream($execution) as $event) {
            if (
                ! $event instanceof \Gplanchat\Durable\Event\ActivityScheduled
                || \Gplanchat\Durable\Store\ActivityEventJournal::hasActivityTaskStartedForAttempt($this->runtime->events, $id, $event->activityId(), 1)
                || \Gplanchat\Durable\Store\ActivityEventJournal::hasTerminalOutcomeForActivity($this->runtime->events, $id, $event->activityId())
            ) {
                continue;
            }

            $message = \Gplanchat\Durable\Transport\ActivityMessage::fromWireMetadata(
                $id,
                $event->activityId(),
                $event->activityName(),
                $event->payload()['payload'],
                $event->metadata(),
            );
            $record = $this->assignments->find($id);
            ($record?->priority === Priority::Interactive ? $this->runtime->interactive : $this->runtime->activityQueue)->send(
                new \Symfony\Component\Messenger\Envelope($message),
            );
            $count++;
        }

        return $count;
    }

    /**
     * Initialize upstream metadata after the admission transaction has committed.
     */
    public function start(StartAssignment $message): void
    {
        $record = $this->assignments->find($message->id);
        if ($record === null || ! $record->active) {
            return;
        }

        $demand = $this->assignments->pending($message->id);
        if ($demand === null) {
            return;
        }

        $this->runtime->dispatcher->dispatchNewWorkflowRun(ExecutionId::fromString($message->id), AssignmentWorkflow::class, [
            'assignment' => $message->id,
            'demand' => (string) $demand->id,
        ]);
    }

    /**
     * Execute precisely the workflow's selected input, acknowledging it atomically.
     *
     * Handlers must make external effects idempotent using the supplied demand ID.
     * This first slice does not yet grant authoritative Vault publication rights.
     */
    public function execute(string $assignment, string $demand): ?string
    {
        $input = $this->assignments->demand($demand) ?? throw new RuntimeException('Admitted input is missing.');
        if ($input->assignment_id !== $assignment) {
            throw new InvalidArgumentException('Input belongs to another Assignment.');
        }

        if ($input->satisfied) {
            return $input->next_id;
        }

        $handler = new \Tempest\Reflection\ClassReflector($this->registry->handler($input->job_class))->getMethod('__invoke');
        $job = map(json_decode($input->payload, true, flags: JSON_THROW_ON_ERROR))->to($input->job_class);
        if (! $job instanceof Job) {
            throw new InvalidArgumentException('Admitted Job or handler is invalid.');
        }

        $record = $this->assignments->find($assignment) ?? throw new RuntimeException('Assignment disappeared before execution.');
        $work = new Work($record->work_type, $record->work_scope, $record->work_mode, $input->observation);
        $context = $this->authority->begin($assignment, $demand);
        try {
            /** @var list<\Tempest\Reflection\ParameterReflector> $parameters */
            $parameters = iterator_to_array($handler->getParameters(), false);
            $this->container->invoke($handler, ...[$parameters[0]->getName() => $job, $parameters[1]->getName() => $context]);
        } catch (\Throwable $error) {
            $this->authority->protect($context, function () use ($context): void {
                \Tempest\Database\query(\App\Operations\Models\AttemptRecord::class)
                    ->update(outcome: \App\Operations\AttemptOutcome::Failed, ended_at: new \DateTimeImmutable())
                    ->where('id', $context->attemptId)
                    ->execute();
            });
            throw $error;
        }

        return $this->authority->protect($context, function () use ($input, $work, $assignment, $context): ?string {
            $this->assignments->lock($work);
            $this->assignments->satisfy($input, null);
            $next = $this->assignments->pending($assignment);
            $nextId = $next === null ? null : (string) $next->id;
            $this->assignments->satisfy($input, $nextId);
            if ($next === null) {
                $record = $this->assignments->find($assignment) ?? throw new RuntimeException('Assignment disappeared during execution.');
                $record->active = false;
                $this->assignments->save($record);
            }

            \Tempest\Database\query(\App\Operations\Models\AttemptRecord::class)
                ->update(outcome: \App\Operations\AttemptOutcome::Completed, ended_at: new \DateTimeImmutable())
                ->where('id', $context->attemptId)
                ->execute();

            return $nextId;
        });
    }

    /**
     * Release active admission only after Durable records a terminal workflow failure.
     */
    private function refreshFailure(AssignmentRecord $record): void
    {
        if (! $record->active) {
            return;
        }

        foreach ($this->runtime->events->readStream(ExecutionId::fromString((string) $record->id)) as $event) {
            if (! $event instanceof WorkflowExecutionFailed) {
                continue;
            }

            $record->active = false;
            $record->error = $event->failureMessage();
            $this->assignments->save($record);
        }
    }
}
