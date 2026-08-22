<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Commands\CommandRepository;
use App\Downloads\DownloadException;
use App\Jobs\JobHandler;
use App\Jobs\JobHandlerContext;
use App\Jobs\JobHandlerRegistry;
use App\Jobs\JobIntent;
use App\Jobs\JobRecord;
use App\Jobs\JobRepository;
use App\Jobs\JobState;
use App\Jobs\JobWorkerService;
use App\Jobs\WorkerProcessProbe;
use App\System\Activity\ActivityEventService;
use App\System\Event\EventPublisher;
use App\System\State\StateTransitionService;
use Tempest\Container\GenericContainer;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

function retryableDownloadWorker(GenericContainer $container, DownloadException $exception): JobWorkerService
{
    $handler = new class ($exception) implements JobHandler {
        public function __construct(
            private DownloadException $exception,
        ) {}

        public function handle(JobRecord $job, JobHandlerContext $context): void
        {
            throw $this->exception;
        }

        public function intent(): JobIntent
        {
            return JobIntent::Enrich;
        }
    };

    return new JobWorkerService(
        jobs: $container->get(JobRepository::class),
        commands: $container->get(CommandRepository::class),
        transitions: $container->get(StateTransitionService::class),
        handlers: new JobHandlerRegistry([$handler]),
        activity: $container->get(ActivityEventService::class),
        publisher: $container->get(EventPublisher::class),
        probe: $container->get(WorkerProcessProbe::class),
    );
}

test('a retryable download failure is parked as pending with a future scheduledAt instead of failing', function (): void {
    $jobs = $this->container->get(JobRepository::class);
    $job = $jobs->create(intent: JobIntent::Enrich, entityType: 'test');

    $worker = retryableDownloadWorker(
        $this->container,
        DownloadException::withCode('download_ytdlp_rate_limited', 'Too many requests', retryable: true),
    );

    expect($worker->processNextJob())->toBeTrue();

    $job = JobRecord::findById($job->id);
    expect($job->state)->toBe(JobState::Pending)
        ->and($job->attempts)->toBe(1)
        ->and($job->startedAt)->toBeNull()
        ->and($job->heartbeatAt)->toBeNull()
        ->and($job->ownerToken)->toBeNull()
        ->and($job->lastError)->toContain('download_ytdlp_rate_limited')
        ->and($job->scheduledAt)->not->toBeNull()
        ->and($job->scheduledAt->isAfter(DateTime::now(Timezone::UTC)))->toBeTrue();

    // A scheduled-for-later job is not claimed by the next tick.
    expect($jobs->claimNextPending())->toBeNull();
});

test('parking a processing job for retry is one guarded state change', function (): void {
    $jobs = $this->container->get(JobRepository::class);
    $created = $jobs->create(intent: JobIntent::Enrich, entityType: 'test');
    $job = $jobs->claimNextPending(ownerToken: 'worker:123');

    expect((string) $job->id)->toBe((string) $created->id)
        ->and($job->state)->toBe(JobState::Processing)
        ->and($job->heartbeatAt)->not->toBeNull();

    $scheduledAt = DateTime::now(Timezone::UTC)->plusSeconds(30);

    expect($jobs->parkForRetry($job, 'retry later', $scheduledAt))->toBeTrue()
        ->and($job->state)->toBe(JobState::Pending)
        ->and($job->startedAt)->toBeNull()
        ->and($job->heartbeatAt)->toBeNull()
        ->and($job->ownerToken)->toBeNull();

    $persisted = JobRecord::findById($job->id);

    expect($persisted->state)->toBe(JobState::Pending)
        ->and($persisted->startedAt)->toBeNull()
        ->and($persisted->heartbeatAt)->toBeNull()
        ->and($persisted->ownerToken)->toBeNull()
        ->and($persisted->lastError)->toBe('retry later')
        ->and($persisted->scheduledAt)->not->toBeNull();
});

test('a retryable download failure fails permanently once maxAttempts is exhausted', function (): void {
    $jobs = $this->container->get(JobRepository::class);
    $job = $jobs->create(intent: JobIntent::Enrich, entityType: 'test');
    $job->maxAttempts = 1;
    $jobs->save($job);

    $worker = retryableDownloadWorker(
        $this->container,
        DownloadException::withCode('download_ytdlp_bot_check', "Sign in to confirm you're not a bot", retryable: true),
    );

    expect($worker->processNextJob())->toBeTrue();

    $job = JobRecord::findById($job->id);
    expect($job->state)->toBe(JobState::Failed)
        ->and($job->lastError)->toContain('download_ytdlp_bot_check');
});

test('claimNextPending skips a job scheduled for the future and claims one scheduled in the past', function (): void {
    $jobs = $this->container->get(JobRepository::class);
    $transitions = $this->container->get(StateTransitionService::class);

    $future = $jobs->create(intent: JobIntent::Enrich, entityType: 'test');
    $future->scheduledAt = DateTime::now(Timezone::UTC)->plusSeconds(300);
    $jobs->save($future);

    $past = $jobs->create(intent: JobIntent::Enrich, entityType: 'test');
    $past->scheduledAt = DateTime::now(Timezone::UTC)->minusSeconds(300);
    $jobs->save($past);

    $claimed = $jobs->claimNextPending();

    expect((string) $claimed->id)->toBe((string) $past->id);
});

test('a non-retryable download failure fails immediately on the first attempt', function (): void {
    $jobs = $this->container->get(JobRepository::class);
    $job = $jobs->create(intent: JobIntent::Enrich, entityType: 'test');

    $worker = retryableDownloadWorker(
        $this->container,
        DownloadException::withCode('download_ytdlp_invalid_uri', 'Invalid URL'),
    );

    expect($worker->processNextJob())->toBeTrue();

    $job = JobRecord::findById($job->id);
    expect($job->state)->toBe(JobState::Failed);
});
