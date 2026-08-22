<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\ApiTokenId;
use App\Auth\AuthContext;
use App\Auth\AuthService;
use App\Auth\UserRepository;
use App\Commands\CommandDispatchService;
use App\Commands\CommandHandler;
use App\Commands\CommandHandlerRegistry;
use App\Commands\CommandRecord;
use App\Commands\CommandRepository;
use App\Commands\CommandType;
use App\Jobs\JobIntent;
use App\Jobs\JobRecord;
use App\Jobs\JobRepository;
use App\Jobs\JobState;
use App\Jobs\JobWorkerService;
use App\Stashes\StashId;
use App\Stashes\StashInputRepository;
use App\Stashes\StashInputType;
use App\Stashes\StashRepository;
use App\Stashes\SyncMode;
use App\System\Activity\ActivityEventRecord;
use App\System\Activity\ActivityEventService;
use App\System\Event\EventPublisher;
use App\System\Scheduler\RoutineDiscoveryScheduler;
use App\System\State\StateTransitionService;
use RuntimeException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;
use Tempest\Database\Builder\QueryBuilders\BuildsQuery;
use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Database;
use Tempest\Database\Direction;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Query;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;
use Tempest\Framework\Testing\Http\TestResponseHelper;
use Tempest\Http\Cookie\Cookie;
use Tempest\Http\Status;
use Tempest\Support\Str\ImmutableString;
use UnitEnum;

test('commands api dispatches stash preflight command', function (): void {
    $headers = $this->authHeaders();

    $response = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => [
            'source_uri' => 'fake://channel/commands-demo',
            'source_title' => 'Commands Demo',
            'origin' => 'browser_extension',
        ],
    ], headers: $headers);

    $response->assertStatus(Status::CREATED);
    expect($response->body['command_type'])->toBe('stash.preflight')
        ->and($response->body['command_state'])->toBe('accepted')
        ->and($response->body['job_ids'])->toHaveCount(1);

    $show = $this->http->get('/api/v1/commands/' . $response->body['command_id'], headers: $headers);
    $show->assertOk();
    expect($show->body['command']['type'])->toBe('stash.preflight')
        ->and($show->body['command']['state'])->toBe('accepted');
});

test('commands api rejects invalid payload', function (): void {
    $headers = $this->authHeaders();

    $response = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => [],
    ], headers: $headers);

    $response->assertStatus(Status::BAD_REQUEST);
    expect($response->body['error']['code'])->toBe('validation_error');
});

test('commands api rejects unsupported type', function (): void {
    $headers = $this->authHeaders();

    $response = $this->http->post('/api/v1/commands', [
        'type' => 'unknown.command',
        'options' => [],
    ], headers: $headers);

    $response->assertStatus(Status::BAD_REQUEST);
});

test('command dispatch rolls back command and activity when its handler fails', function (): void {
    $commandsBefore = count(CommandRecord::select()->all());
    $jobsBefore = count(JobRecord::select()->all());
    $activitiesBefore = count(ActivityEventRecord::select()->all());
    $handler = new class implements CommandHandler {
        public function type(): CommandType
        {
            return CommandType::SystemStorageCheck;
        }

        public function validate(array $options): void {}

        public function createJobs(CommandRecord $command, array $options): array
        {
            throw new RuntimeException('forced handler failure');
        }

        public function extras(CommandRecord $command, array $options): array
        {
            return [];
        }
    };
    $dispatch = new CommandDispatchService(
        commands: $this->container->get(CommandRepository::class),
        handlers: new CommandHandlerRegistry([$handler]),
        activity: $this->container->get(ActivityEventService::class),
        publisher: $this->container->get(EventPublisher::class),
        database: $this->container->get(Database::class),
    );

    expect(fn() => $dispatch->dispatch(CommandType::SystemStorageCheck, []))
        ->toThrow(RuntimeException::class, 'Command dispatch failed.');

    expect(CommandRecord::select()->all())->toHaveCount($commandsBefore)
        ->and(JobRecord::select()->all())->toHaveCount($jobsBefore)
        ->and(ActivityEventRecord::select()->all())->toHaveCount($activitiesBefore);
});

test('command dispatch publishes nothing when commit fails after creating its durable rows', function (): void {
    $hub = new class implements HubInterface {
        /** @var list<Update> */
        public array $published = [];

        public function getPublicUrl(): string
        {
            return 'http://127.0.0.1:8474/.well-known/mercure';
        }

        public function getFactory(): ?TokenFactoryInterface
        {
            return null;
        }

        public function publish(Update $update): string
        {
            $this->published[] = $update;

            return 'fake-id';
        }
    };
    $this->container->singleton(HubInterface::class, $hub);

    $commandsBefore = count(CommandRecord::select()->all());
    $jobsBefore = count(JobRecord::select()->all());
    $activitiesBefore = count(ActivityEventRecord::select()->all());
    $realDatabase = $this->container->get(Database::class);
    $database = new class ($realDatabase) implements Database {
        public function __construct(private Database $inner) {}

        public DatabaseDialect $dialect { get => $this->inner->dialect; }

        public string|UnitEnum|null $tag { get => $this->inner->tag; }

        public function execute(BuildsQuery|Query $query): void
        {
            $this->inner->execute($query);
        }

        public function getLastInsertId(): ?PrimaryKey
        {
            return $this->inner->getLastInsertId();
        }

        public function fetch(BuildsQuery|Query $query): array
        {
            return $this->inner->fetch($query);
        }

        public function fetchFirst(BuildsQuery|Query $query): ?array
        {
            return $this->inner->fetchFirst($query);
        }

        public function withinTransaction(callable $callback): bool
        {
            return $this->inner->withinTransaction(function () use ($callback): void {
                $callback();

                throw new RuntimeException('forced commit failure');
            });
        }

        public function getRawSql(Query $query): ImmutableString
        {
            return $this->inner->getRawSql($query);
        }
    };
    $this->container->singleton(Database::class, $database);
    $dispatch = $this->container->get(CommandDispatchService::class);

    expect(fn() => $dispatch->dispatch(CommandType::SystemStorageCheck, []))
        ->toThrow(RuntimeException::class, 'Command dispatch failed.');

    expect(CommandRecord::select()->all())->toHaveCount($commandsBefore)
        ->and(JobRecord::select()->all())->toHaveCount($jobsBefore)
        ->and(ActivityEventRecord::select()->all())->toHaveCount($activitiesBefore)
        ->and($hub->published)->toBeEmpty();
});

test('system storage check command creates and completes storage job', function (): void {
    $headers = $this->authHeaders();

    $response = $this->http->post('/api/v1/commands', [
        'type' => 'system.storage_check',
        'options' => [],
    ], headers: $headers);

    $response->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $job = $this->http->get('/api/v1/jobs/' . $response->body['job_ids'][0], headers: $headers);
    $job->assertOk();
    expect($job->body['job']['intent'])->toBe('storage_check')
        ->and($job->body['job']['state'])->toBe('ready');

    $command = $this->http->get('/api/v1/commands/' . $response->body['command_id'], headers: $headers);
    $command->assertOk();
    expect($command->body['command']['state'])->toBe('completed')
        ->and($command->body['command']['result']['status'])->not->toBeNull();
});

test('jobs api lists recent jobs', function (): void {
    $headers = $this->authHeaders();

    $this->http->post('/api/v1/commands', [
        'type' => 'system.storage_check',
        'options' => [],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $jobs = $this->http->get('/api/v1/jobs', headers: $headers);
    $jobs->assertOk();
    expect($jobs->body['jobs'])->not->toBeEmpty();
});

test('jobs api still surfaces the actively processing job when it is older than the 50 most recent', function (): void {
    $headers = $this->authHeaders();
    $jobs = $this->container->get(JobRepository::class);
    $transitions = $this->container->get(StateTransitionService::class);

    $oldest = $jobs->create(intent: JobIntent::Enrich, entityType: 'media_item', entityId: null);
    $transitions->transitionJob($oldest, JobState::Processing);

    for ($i = 0; $i < 55; $i++) {
        $jobs->create(intent: JobIntent::Enrich, entityType: 'media_item', entityId: null);
    }

    $response = $this->http->get('/api/v1/jobs', headers: $headers)->assertOk();

    $ids = array_column($response->body['jobs'], 'id');
    expect($ids)->toContain((string) $oldest->id)
        ->and($response->body['jobs'][array_search((string) $oldest->id, $ids, true)]['state'])->toBe('processing');
});

test('jobs api exposes entity_type and entity_id for a media item download job', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('jobs-entity-link');

    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $jobs = $this->http->get('/api/v1/jobs', headers: $headers)->assertOk();

    $downloadJobs = array_values(array_filter(
        $jobs->body['jobs'],
        static fn(array $job): bool => $job['entity_type'] === 'media_item' && $job['entity_id'] === $mediaItemId,
    ));

    expect($downloadJobs)->not->toBeEmpty();

    $this->processAllJobs();
});

test('job worker records failure with last error', function (): void {
    $headers = $this->authHeaders();
    $jobs = $this->container->get(JobRepository::class);

    $job = $jobs->create(
        intent: JobIntent::Enrich,
        entityType: 'test',
    );

    $worker = $this->container->get(JobWorkerService::class);
    expect($worker->processNextJob())->toBeTrue();

    $job = JobRecord::findById($job->id);
    expect($job->state)->toBe(JobState::Failed)
        ->and($job->lastError)->toContain('No handler registered');
});

test('stale processing jobs are recovered or failed based on attempts', function (): void {
    $headers = $this->authHeaders();

    $created = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'fake://channel/stale-demo'],
    ], headers: $headers);

    $job = JobRecord::findById(new PrimaryKey($created->body['job_ids'][0]));
    $job->state = JobState::Processing;
    $job->attempts = 1;
    $job->maxAttempts = 3;
    $job->heartbeatAt = DateTime::now(Timezone::UTC)->minusSeconds(300);
    $job->startedAt = DateTime::now(Timezone::UTC);
    $job->save();

    $worker = $this->container->get(JobWorkerService::class);
    expect($worker->recoverStaleJobs())->toBe(1);

    $job = JobRecord::findById($job->id);
    expect($job->state)->toBe(JobState::Pending)
        ->and($job->lastError)->toContain('stalled');

    $job->state = JobState::Processing;
    $job->attempts = 3;
    $job->heartbeatAt = DateTime::now(Timezone::UTC)->minusSeconds(300);
    $job->save();

    expect($worker->recoverStaleJobs())->toBe(1);
    $job = JobRecord::findById($job->id);
    expect($job->state)->toBe(JobState::Failed);
});

test('command dispatch writes activity events', function (): void {
    $headers = $this->authHeaders();

    $this->http->post('/api/v1/commands', [
        'type' => 'system.storage_check',
        'options' => [],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->processAllJobs();

    $activities = ActivityEventRecord::select()->all();
    expect($activities)->not->toBeEmpty();

    $types = array_map(static fn($event): string => $event->type, $activities);
    expect($types)->toContain('command.accepted')
        ->and($types)->toContain('job.started')
        ->and($types)->toContain('storage_check.completed')
        ->and($types)->toContain('command.completed');
});

test('events subscription endpoint requires authentication and sets a scoped cookie', function (): void {
    $users = $this->container->get(UserRepository::class);
    $users->createAdmin(
        username: 'owner',
        passwordHash: password_hash('secret-password', PASSWORD_DEFAULT),
    );

    $this->http->get('/api/v1/events/subscription')->assertStatus(Status::UNAUTHORIZED);

    $headers = $this->authHeaders();
    $response = $this->http->get('/api/v1/events/subscription', headers: $headers)->assertOk();

    $mercureCookie = mercureAuthorizationCookieFrom($response);

    expect($mercureCookie)->not->toBeNull()
        ->and($mercureCookie->path)->toBe('/.well-known/mercure')
        ->and($mercureCookie->httpOnly)->toBeTrue();
});

test('mercure cookie carries the raw subscriber JWT, not Tempest\'s encrypted cookie envelope', function (): void {
    // Caddy's Mercure hub decodes this cookie itself, using MercureSecret --
    // it has no knowledge of Tempest's own per-cookie AES-256-GCM encryption
    // (SetCookieHeadersMiddleware), which wraps every cookie value in a
    // {"payload":...,"iv":...,"tag":...,"signature":...} envelope unless the
    // cookie key is explicitly whitelisted as plaintext. A JWT has exactly
    // two dots (header.payload.signature); the encrypted envelope, being a
    // single base64 blob, has none.
    $headers = $this->authHeaders();

    $response = $this->http->get('/api/v1/events/subscription', headers: $headers)->assertOk();
    $jwt = mercureAuthorizationCookieFrom($response)->value;

    expect(substr_count($jwt, '.'))->toBe(2);

    [$header, $payload] = explode('.', $jwt);
    $decodedPayload = json_decode(base64_decode(strtr($payload, '-_', '+/')), associative: true);

    expect(json_decode(base64_decode(strtr($header, '-_', '+/')), associative: true)['alg'] ?? null)->not->toBeNull()
        ->and($decodedPayload['mercure']['subscribe'] ?? null)->not->toBeNull();
});

test('mercure cookie is not Secure when reached with no reverse proxy, matching the http test baseUri', function (): void {
    $headers = $this->authHeaders();

    $response = $this->http->get('/api/v1/events/subscription', headers: $headers)->assertOk();

    expect(mercureAuthorizationCookieFrom($response)->secure)->toBeFalse();
});

test('mercure cookie is Secure when a reverse proxy reports X-Forwarded-Proto: https, regardless of baseUri', function (): void {
    $headers = $this->authHeaders();

    $response = $this->http->get('/api/v1/events/subscription', headers: [
        ...$headers,
        'X-Forwarded-Proto' => 'https',
    ])->assertOk();

    expect(mercureAuthorizationCookieFrom($response)->secure)->toBeTrue();
});

test('mercure cookie is not Secure when X-Forwarded-Proto reports http, even behind a proxy', function (): void {
    $headers = $this->authHeaders();

    $response = $this->http->get('/api/v1/events/subscription', headers: [
        ...$headers,
        'X-Forwarded-Proto' => 'http',
    ])->assertOk();

    expect(mercureAuthorizationCookieFrom($response)->secure)->toBeFalse();
});

function mercureAuthorizationCookieFrom(TestResponseHelper $response): ?Cookie
{
    $setCookie = $response->response->getHeader('set-cookie')?->values ?? [];

    foreach ($setCookie as $value) {
        $cookie = Cookie::createFromString($value);
        if ($cookie->key === 'mercureAuthorization') {
            return $cookie;
        }
    }

    return null;
}

test('request auth context does not leak between http requests', function (): void {
    $headers = $this->authHeaders();

    $this->http->get('/api/v1/auth/me', headers: $headers)->assertOk();

    $context = $this->container->get(AuthContext::class);
    expect($context->user())->toBeNull();

    $this->http->get('/api/v1/auth/me')->assertStatus(Status::UNAUTHORIZED);
});

test('bearer auth does not leak to subsequent unauthenticated requests', function (): void {
    $headers = $this->authHeaders();

    $this->http->get('/api/v1/jobs', headers: $headers)->assertOk();
    $this->http->get('/api/v1/jobs')->assertStatus(Status::UNAUTHORIZED);
});

test('api token uses stashd_pat prefix and supports lookup and revoke', function (): void {
    $users = $this->container->get(UserRepository::class);
    $auth = $this->container->get(AuthService::class);
    $user = $users->createAdmin(
        username: 'owner',
        passwordHash: password_hash('secret-password', PASSWORD_DEFAULT),
    );

    $created = $auth->createApiToken($user, 'phase2-token');
    expect($created['token'])->toStartWith('stashd_pat_')
        ->and($created['token_preview'])->toStartWith('stashd_pat_');

    $headers = ['Authorization' => 'Bearer ' . $created['token']];
    $this->http->get('/api/v1/auth/me', headers: $headers)->assertOk();

    $auth->revokeApiToken($user, ApiTokenId::parse($created['id']));
    $this->http->get('/api/v1/auth/me', headers: $headers)->assertStatus(Status::UNAUTHORIZED);
});

test('scheduler creates sync commands for due automatic stash inputs', function (): void {
    $stashRepo = $this->container->get(StashRepository::class);
    $inputRepo = $this->container->get(StashInputRepository::class);
    $scheduler = $this->container->get(RoutineDiscoveryScheduler::class);

    $stash = $stashRepo->create('Scheduler Stash');
    $inputRepo->create(
        stashId: StashId::parse((string) $stash->id),
        providerKey: 'fake',
        inputType: StashInputType::Channel,
        sourceUri: 'fake://channel/scheduler-demo',
        providerInputId: 'scheduler-demo',
        title: 'Scheduler Channel',
        syncMode: SyncMode::Automatic,
    );

    expect($scheduler->runDueChecks())->toBe(1);

    $command = CommandRecord::select()
        ->where('type', CommandType::StashSyncInput)
        ->orderBy('createdAt', Direction::DESC)
        ->first();

    expect($command)->not->toBeNull()
        ->and($command->options['stash_input_id'] ?? null)->toBeString();
});
