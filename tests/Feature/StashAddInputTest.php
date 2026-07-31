<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Stashes\StashInputRecord;
use App\Stashes\StashItemRecord;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\MediaItemRecord;
use App\Vault\MediaItemSourceRecord;
use App\Vault\MediaItemState;
use Tempest\Database\Builder\QueryBuilders\BuildsQuery;
use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Database;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Query;
use Tempest\Http\Status;
use Tempest\Support\Str\ImmutableString;
use UnitEnum;

test('add input command commits discovered items into an existing stash', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', [
        'name' => 'Preflight Channel Stash',
    ], headers: $headers)->assertStatus(Status::CREATED);
    $stashId = $stash->body['stash']['id'];

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => [
            'source_uri' => 'fake://channel/create-from-preflight',
            'source_title' => 'Create From Preflight Channel',
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->processAllJobs();

    $add = $this->http->post('/api/v1/stashes/' . $stashId . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $add->body['command_id'], headers: $headers);
    $command->assertOk();
    expect($command->body['command']['state'])->toBe('completed')
        ->and($command->body['command']['result']['stash_id'])->toBe($stashId)
        ->and($command->body['command']['result']['media_items_created'])->toBe(3)
        ->and($command->body['command']['result']['stash_items_created'])->toBe(3);

    $input = StashInputRecord::findById(new PrimaryKey($command->body['command']['result']['stash_input_id']));
    expect($input)->not->toBeNull()
        ->and($input->sourceUri)->toBe('fake://channel/create-from-preflight');

    expect(MediaItemRecord::count()->execute())->toBe(3)
        ->and(StashItemRecord::count()->execute())->toBe(3);
});

test('add-input persistence rolls back completely and retries cleanly after a transaction failure', function (): void {
    $headers = $this->authHeaders();
    $stash = $this->http->post('/api/v1/stashes', ['name' => 'Atomic Input'], headers: $headers)
        ->assertStatus(Status::CREATED);
    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'fake://channel/atomic-input'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $realDatabase = $this->container->get(Database::class);
    $database = new class ($realDatabase) implements Database {
        public bool $fail = true;

        public function __construct(private Database $inner)
        {
        }

        public DatabaseDialect $dialect { get => $this->inner->dialect; }

        public null|string|UnitEnum $tag { get => $this->inner->tag; }

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

                if ($this->fail) {
                    throw new \RuntimeException('forced rollback');
                }
            });
        }

        public function getRawSql(Query $query): ImmutableString
        {
            return $this->inner->getRawSql($query);
        }
    };
    $this->container->singleton(Database::class, $database);

    $service = $this->container->get(\App\Stashes\CreateStashFromDiscovery::class);
    $stashRecord = $this->container->get(\App\Stashes\StashRepository::class)
        ->find(\App\Stashes\StashId::parse($stash->body['stash']['id']));
    $preflightCommand = $this->container->get(\App\Commands\CommandRepository::class)
        ->find(\App\Commands\CommandId::parse($preflight->body['command_id']));

    expect(fn () => $service->commitInput($stashRecord, $preflightCommand))
        ->toThrow(\RuntimeException::class, 'Failed to commit stash input.');

    expect(StashInputRecord::count()->execute())->toBe(0)
        ->and(StashItemRecord::count()->execute())->toBe(0)
        ->and(MediaItemRecord::count()->execute())->toBe(0)
        ->and(MediaItemSourceRecord::count()->execute())->toBe(0);

    $database->fail = false;
    $result = $service->commitInput($stashRecord, $preflightCommand);

    expect($result->mediaItemsCreated)->toBe(3)
        ->and($result->stashItemsCreated)->toBe(3)
        ->and(StashInputRecord::count()->execute())->toBe(1)
        ->and(StashItemRecord::count()->execute())->toBe(3)
        ->and(MediaItemSourceRecord::count()->execute())->toBe(3);

    $downloadCommands = \App\Commands\CommandRecord::select()->where('type', 'item.download')->all();

    $service->commitInput($stashRecord, $preflightCommand);

    expect(\App\Commands\CommandRecord::select()->where('type', 'item.download')->all())
        ->toHaveCount(count($downloadCommands));
});

test('add input reuses existing media items by provider identity', function (): void {
    $headers = $this->authHeaders();
    $mediaItems = $this->container->get(\App\Vault\MediaItemRepository::class);

    $mediaItems->create(
        providerKey: 'fake',
        providerItemId: 'dedupe-a-episode-1',
        canonicalUri: 'fake://item/dedupe-a-episode-1',
        title: 'Existing Episode',
    );

    $stash = $this->http->post('/api/v1/stashes', ['name' => 'Dedupe A'], headers: $headers)->assertStatus(Status::CREATED);

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'fake://channel/dedupe-a'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $add = $this->http->post('/api/v1/stashes/' . $stash->body['stash']['id'] . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $result = $this->http->get('/api/v1/commands/' . $add->body['command_id'], headers: $headers);
    $result->assertOk();
    expect($result->body['command']['result']['media_items_created'])->toBe(2)
        ->and($result->body['command']['result']['media_items_reused'])->toBe(1)
        ->and(MediaItemRecord::count()->execute())->toBe(3);
});

test('add input rejects incomplete preflight commands', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', ['name' => 'Should Not Add'], headers: $headers)->assertStatus(Status::CREATED);

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'fake://channel/incomplete'],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $response = $this->http->post('/api/v1/stashes/' . $stash->body['stash']['id'] . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
    ], headers: $headers);

    $response->assertStatus(Status::BAD_REQUEST);
    expect($response->body['error']['code'])->toBe('validation_error');
});

test('add input returns 404 for an unknown stash', function (): void {
    $headers = $this->authHeaders();

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'fake://channel/no-such-stash'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $response = $this->http->post('/api/v1/stashes/stash_01ARZ3NDEKTSV4RRFFQ69G5FAV/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
    ], headers: $headers);

    $response->assertStatus(Status::NOT_FOUND);
    expect($response->body['error']['code'])->toBe('not_found');
});

test('add input persists discovered descriptions onto new media items', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', ['name' => 'With Descriptions'], headers: $headers)->assertStatus(Status::CREATED);

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'fake://channel/with-descriptions'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $this->http->post('/api/v1/stashes/' . $stash->body['stash']['id'] . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $media = MediaItemRecord::select()
        ->where('providerKey', 'fake')
        ->where('providerItemId', 'with-descriptions-episode-1')
        ->first();

    expect($media)->not->toBeNull()
        ->and($media->description)->toBe('Fake episode 1 description.');
});

test('add input leaves existing media item description unchanged when reused', function (): void {
    $headers = $this->authHeaders();
    $mediaItems = $this->container->get(\App\Vault\MediaItemRepository::class);

    $mediaItems->create(
        providerKey: 'fake',
        providerItemId: 'dedupe-desc-episode-1',
        canonicalUri: 'fake://item/dedupe-desc-episode-1',
        title: 'Existing Episode',
        description: 'Manually curated description.',
    );

    $stash = $this->http->post('/api/v1/stashes', ['name' => 'Dedupe Desc'], headers: $headers)->assertStatus(Status::CREATED);

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'fake://channel/dedupe-desc'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $this->http->post('/api/v1/stashes/' . $stash->body['stash']['id'] . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $media = MediaItemRecord::select()
        ->where('providerKey', 'fake')
        ->where('providerItemId', 'dedupe-desc-episode-1')
        ->first();

    expect($media->description)->toBe('Manually curated description.');
});

test('add input with video policy automatically downloads items without a manual command', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', [
        'name' => 'Auto Download',
        'download_policy' => 'video',
    ], headers: $headers)->assertStatus(Status::CREATED);
    $stashId = $stash->body['stash']['id'];

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'fake://channel/auto-download'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $this->http->post('/api/v1/stashes/' . $stashId . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $stashItems = StashItemRecord::select()->where('stashId', $stashId)->all();
    expect($stashItems)->toHaveCount(3);

    $assets = $this->container->get(AssetRepository::class);

    foreach ($stashItems as $stashItem) {
        $media = MediaItemRecord::findById(new PrimaryKey((string) $stashItem->mediaItemId));
        expect($media?->state)->toBe(MediaItemState::Ready);

        $original = $assets->findByMediaItemAndRole(
            $stashItem->mediaItemId,
            AssetRole::VaultOriginal,
        );
        expect($original?->state)->toBe(AssetState::Ready);
    }
});

test('add input with metadata_only policy enqueues no downloads', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', [
        'name' => 'No Auto Download',
        'download_policy' => 'metadata_only',
    ], headers: $headers)->assertStatus(Status::CREATED);
    $stashId = $stash->body['stash']['id'];

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'fake://channel/no-auto-download'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $this->http->post('/api/v1/stashes/' . $stashId . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $stashItems = StashItemRecord::select()->where('stashId', $stashId)->all();
    expect($stashItems)->toHaveCount(3);

    foreach ($stashItems as $stashItem) {
        $media = MediaItemRecord::findById(new PrimaryKey((string) $stashItem->mediaItemId));
        expect($media?->state)->toBe(MediaItemState::Discovered);
    }

    expect(\App\Commands\CommandRecord::select()->where('type', 'item.download')->all())->toHaveCount(0);
});

test('add input commits a second input from a different source into the same stash', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', ['name' => 'Multi Input'], headers: $headers)->assertStatus(Status::CREATED);
    $stashId = $stash->body['stash']['id'];

    $addInput = function (string $sourceUri) use ($headers, $stashId): array {
        $preflight = $this->http->post('/api/v1/commands', [
            'type' => 'stash.preflight',
            'options' => ['source_uri' => $sourceUri],
        ], headers: $headers)->assertStatus(Status::CREATED);
        $this->processAllJobs();

        $add = $this->http->post('/api/v1/stashes/' . $stashId . '/inputs', [
            'preflight_command_id' => $preflight->body['command_id'],
        ], headers: $headers)->assertStatus(Status::CREATED);
        $this->processAllJobs();

        $command = $this->http->get('/api/v1/commands/' . $add->body['command_id'], headers: $headers);
        $command->assertOk();

        return $command->body['command']['result'];
    };

    $first = $addInput('fake://channel/multi-input-a');
    $second = $addInput('fake://playlist/multi-input-b');

    expect($first['stash_input_id'])->not->toBe($second['stash_input_id'])
        ->and(StashInputRecord::count()->execute())->toBe(2)
        ->and(StashItemRecord::select()->where('stashId', $stashId)->all())->toHaveCount(3 + 20);
});

test('preflight review exposes discovered items for commit flow', function (): void {
    $headers = $this->authHeaders();

    $preflight = $this->http->post('/api/v1/stashes/preflight', [
        'source_uri' => 'fake://channel/review-items',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->processAllJobs();

    $review = $this->http->get(
        '/api/v1/stashes/preflight/' . $preflight->body['command_id'] . '/review',
        headers: $headers,
    );
    $review->assertOk();
    expect($review->body['preflight']['discovery']['discovered_items'])->toHaveCount(3)
        ->and($review->body['preflight']['discovery']['sample_items'])->toHaveCount(3);
});
