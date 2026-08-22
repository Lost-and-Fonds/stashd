<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\ApiTokenId;
use App\Auth\AuthService;
use App\Auth\UserRepository;
use App\Jobs\JobRecord;
use App\Jobs\JobState;
use App\System\Event\EventPublisher;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;
use Tempest\Database\PrimaryKey;
use Tempest\Http\Status;

test('post commands returns snake_case response keys', function (): void {
    $headers = $this->authHeaders();

    $response = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'fake://channel/snake-case'],
    ], headers: $headers);

    $response->assertStatus(Status::CREATED);
    expect(array_keys($response->body))->toContain('command_id', 'command_state', 'job_ids', 'review_url')
        ->and(array_keys($response->body))->not->toContain('commandId', 'jobIds');
});

test('get command includes jobs and result after worker completion', function (): void {
    $headers = $this->authHeaders();

    $created = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'fake://channel/show-command'],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->processAllJobs();

    $show = $this->http->get('/api/v1/commands/' . $created->body['command_id'], headers: $headers);
    $show->assertOk();
    expect($show->body['command']['state'])->toBe('completed')
        ->and($show->body['jobs'])->toHaveCount(1)
        ->and($show->body['command']['result']['discovery']['estimated_item_count'])->toBe(3);
});

test('get job returns progress heartbeat and error fields in snake_case', function (): void {
    $headers = $this->authHeaders();

    $created = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'fake://channel/job-fields'],
    ], headers: $headers);

    $this->processAllJobs();

    $job = $this->http->get('/api/v1/jobs/' . $created->body['job_ids'][0], headers: $headers);
    $job->assertOk();
    expect($job->body['job'])->toHaveKeys([
        'progress_current',
        'progress_total',
        'progress_percent',
        'progress_label',
        'heartbeat_at',
        'last_error',
    ])->and($job->body['job']['progress_total'])->toBe(3);
});

test('invalid command payloads return stable error envelopes', function (): void {
    $headers = $this->authHeaders();

    $missingType = $this->http->post('/api/v1/commands', ['options' => []], headers: $headers);
    $missingType->assertStatus(Status::BAD_REQUEST);
    expect($missingType->body['error'])->toBe([
        'code' => 'validation_error',
        'message' => 'type is required.',
    ]);

    $invalidPayload = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => [],
    ], headers: $headers);
    $invalidPayload->assertStatus(Status::BAD_REQUEST);
    expect($invalidPayload->body['error']['code'])->toBe('validation_error')
        ->and($invalidPayload->body['error']['message'])->toContain('source_uri');
});

test('job failed events publish redacted last_error to the Mercure hub', function (): void {
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

    $publisher = $this->container->get(EventPublisher::class);
    $job = JobRecord::select()->first();

    if ($job === null) {
        $headers = $this->authHeaders();
        $created = $this->http->post('/api/v1/commands', [
            'type' => 'system.storage_check',
            'options' => [],
        ], headers: $headers);
        $job = JobRecord::findById(new PrimaryKey($created->body['job_ids'][0]));
    }

    $job->state = JobState::Failed;
    $job->lastError = 'Bearer stashd_pat_' . str_repeat('a', 40) . ' failed upstream';
    $job->save();

    $publisher->jobFailed($job);

    $lastUpdate = end($hub->published);
    $payload = json_decode($lastUpdate->getData(), associative: true);

    expect($payload['event'])->toBe('job.failed')
        ->and($payload['id'])->toBe((string) $job->id)
        ->and($payload['entity_type'])->toBe($job->entityType)
        ->and($payload)->not->toHaveKey('payload')
        ->and($payload['last_error'])->not->toContain('stashd_pat_')
        ->and($payload['last_error'])->toContain('[REDACTED]');
});

test('bearer auth takes precedence over session for the same request', function (): void {
    $users = $this->container->get(UserRepository::class);
    $auth = $this->container->get(AuthService::class);

    $owner = $users->createAdmin(
        username: 'owner',
        passwordHash: password_hash('secret-password', PASSWORD_DEFAULT),
    );

    $login = $this->http->post('/api/v1/auth/login', [
        'username' => 'owner',
        'password' => 'secret-password',
    ])->assertOk();

    useSessionCookieFrom($login);

    $token = $auth->createApiToken($owner, 'bearer-precedence');
    $headers = ['Authorization' => 'Bearer ' . $token['token']];

    $this->http->get('/api/v1/auth/me', headers: $headers)->assertOk();
    $this->http->get('/api/v1/auth/me')->assertOk();
});

test('revoked bearer token is rejected even when session cookie would authenticate', function (): void {
    $auth = $this->container->get(AuthService::class);
    $users = $this->container->get(UserRepository::class);

    $owner = $users->createAdmin(
        username: 'owner',
        passwordHash: password_hash('secret-password', PASSWORD_DEFAULT),
    );

    $this->http->post('/api/v1/auth/login', [
        'username' => 'owner',
        'password' => 'secret-password',
    ])->assertOk();

    $created = $auth->createApiToken($owner, 'revoke-with-session');
    $headers = ['Authorization' => 'Bearer ' . $created['token']];

    $auth->revokeApiToken($owner, ApiTokenId::parse($created['id']));

    $this->http->get('/api/v1/auth/me', headers: $headers)->assertStatus(Status::UNAUTHORIZED);
});
