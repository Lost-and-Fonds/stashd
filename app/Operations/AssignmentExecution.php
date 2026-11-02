<?php

declare(strict_types=1);

namespace App\Operations;

use App\Operations\Authority\PublicationAuthority;
use App\Operations\Durable\AssignmentWorkflow;
use App\Operations\Durable\DurableRuntime;
use App\Operations\Durable\JobRegistry;
use App\Operations\Durable\StartAssignment;
use App\Operations\Models\Attempt;
use App\Operations\Models\Demand;
use App\Operations\Persistence\AdmissionLock;
use DateTimeImmutable;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\ActivityEventJournal;
use Gplanchat\Durable\Transport\ActivityMessage;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Tempest\Container\Container;
use Tempest\Container\Singleton;
use Tempest\Reflection\ClassReflector;
use Tempest\Reflection\ParameterReflector;
use Throwable;

use function Tempest\Database\query;
use function Tempest\Mapper\map;

/**
 * Run retained demand through Durable while keeping acknowledgment and takeover fenced.
 */
#[Singleton]
final readonly class AssignmentExecution
{
    public function __construct(
        private AdmissionLock $admission,
        private DurableRuntime $runtime,
        private JobRegistry $registry,
        private Container $container,
        private PublicationAuthority $authority,
    ) {}

    /**
     * Reissue journalled schedules that never started, without replaying side effects.
     *
     * Duplicate invitations are safe through Durable's claim and settled guards.
     * This deliberately does not retry failed or already-started activities.
     */
    public function recover(string $id): int
    {
        /** @var Assignment|null $record */
        $record = query(Assignment::class)->select()->where('uuid', $id)->first();
        if ($record === null || ! $record->active) {
            return 0;
        }

        $execution = ExecutionId::fromString($id);
        $count = 0;
        foreach ($this->runtime->events->readStream($execution) as $event) {
            if (
                ! $event instanceof ActivityScheduled
                || ActivityEventJournal::hasActivityTaskStartedForAttempt($this->runtime->events, $id, $event->activityId(), 1)
                || ActivityEventJournal::hasTerminalOutcomeForActivity($this->runtime->events, $id, $event->activityId())
            ) {
                continue;
            }

            $message = ActivityMessage::fromWireMetadata(
                $id,
                $event->activityId(),
                $event->activityName(),
                $event->payload()['payload'],
                $event->metadata(),
            );
            /** @var Assignment|null $record */
            $record = query(Assignment::class)->select()->where('uuid', $id)->first();
            ($record?->priority === Priority::Interactive ? $this->runtime->interactive : $this->runtime->activityQueue)->send(
                new Envelope($message),
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
        /** @var Assignment|null $record */
        $record = query(Assignment::class)->select()->where('uuid', $message->id)->first();
        if ($record === null || ! $record->active) {
            return;
        }

        /** @var Demand|null $demand */
        $demand = query(Demand::class)->select()->where('assignment_id', $message->id)->where('satisfied', false)->orderBy('uuid')->first();
        if ($demand === null) {
            return;
        }

        $this->runtime->dispatcher->dispatchNewWorkflowRun(ExecutionId::fromString($message->id), AssignmentWorkflow::class, [
            'assignment' => $message->id,
            'demand' => (string) $demand->uuid,
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
        /** @var Demand $input */
        $input = query(Demand::class)->select()->where('uuid', $demand)->first() ?? throw new RuntimeException('Admitted input is missing.');
        if ($input->assignment_id !== $assignment) {
            throw new InvalidArgumentException('Input belongs to another Assignment.');
        }

        if ($input->satisfied) {
            return $input->next_id;
        }

        /** @var Assignment $record */
        $record = query(Assignment::class)->select()->where('uuid', $assignment)->first() ?? throw new RuntimeException('Assignment disappeared before execution.');
        $handler = new ClassReflector($this->registry->handler($this->registry->job($record->job_type)))->getMethod('__invoke');
        $job = map(json_decode($input->payload, true, flags: JSON_THROW_ON_ERROR))->to($this->registry->job($record->job_type));
        if (! $job instanceof Job) {
            throw new InvalidArgumentException('Admitted Job or handler is invalid.');
        }

        $context = $this->authority->begin($assignment, $demand);
        try {
            /** @var list<ParameterReflector> $parameters */
            $parameters = iterator_to_array($handler->getParameters(), false);
            $this->container->invoke($handler, ...[$parameters[0]->getName() => $job, $parameters[1]->getName() => $context]);
        } catch (Throwable $error) {
            $this->authority->protect($context, function () use ($context): void {
                query(Attempt::class)
                    ->update(outcome: AttemptOutcome::Failed, ended_at: new DateTimeImmutable())
                    ->where('uuid', $context->attemptId->uuidFor(Attempt::class))
                    ->execute();
            });
            throw $error;
        }

        return $this->authority->protect($context, function () use ($input, $record, $assignment, $context): ?string {
            $this->admission->lock($record->job_type, $record->work_key);
            query(Demand::class)
                ->update(satisfied: true)
                ->where('uuid', (string) $input->uuid)
                ->execute();
            /** @var Demand|null $next */
            $next = query(Demand::class)->select()->where('assignment_id', $assignment)->where('satisfied', false)->orderBy('uuid')->first();
            $nextId = $next === null ? null : (string) $next->uuid;
            query(Demand::class)
                ->update(next_id: $nextId)
                ->where('uuid', (string) $input->uuid)
                ->execute();
            if ($next === null) {
                /** @var Assignment $record */
                $record = query(Assignment::class)->select()->where('uuid', $assignment)->first() ?? throw new RuntimeException('Assignment disappeared during execution.');
                $record->active = false;
                query(Assignment::class)
                    ->update(priority: $record->priority, active: $record->active, error: $record->error)
                    ->where('uuid', (string) $record->uuid)
                    ->execute();
            }

            query(Attempt::class)
                ->update(outcome: AttemptOutcome::Completed, ended_at: new DateTimeImmutable())
                ->where('uuid', $context->attemptId->uuidFor(Attempt::class))
                ->execute();

            return $nextId;
        });
    }

    /**
     * Release active admission only after Durable records a terminal workflow failure.
     */
    public function reconcileFailure(Assignment $record): void
    {
        if (! $record->active) {
            return;
        }

        foreach ($this->runtime->events->readStream(ExecutionId::fromString((string) $record->uuid)) as $event) {
            if (! $event instanceof WorkflowExecutionFailed) {
                continue;
            }

            $record->active = false;
            $record->error = $event->failureMessage();
            query(Assignment::class)
                ->update(priority: $record->priority, active: $record->active, error: $record->error)
                ->where('uuid', (string) $record->uuid)
                ->execute();
        }
    }
}
