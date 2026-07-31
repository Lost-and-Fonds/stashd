<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Stashes\StashInputRecord;
use App\Stashes\StashItemRecord;
use App\Stashes\StashRecord;
use App\Vault\MediaItemSourceRecord;
use RuntimeException;
use Tempest\Database\Builder\QueryBuilders\BuildsQuery;
use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Database;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Query;
use Tempest\Http\Status;
use Tempest\Support\Str\ImmutableString;
use UnitEnum;

test('PATCH stash updates provided fields and leaves others unchanged', function (): void {
    [$headers, $stashId] = $this->bootstrapFakeDownloadStash('patch-stash');

    $before = StashRecord::findById(new PrimaryKey($stashId));

    $response = $this->http->patch('/api/v1/stashes/' . $stashId, [
        'name' => 'Renamed Stash',
        'sync_mode' => 'manual',
    ], headers: $headers);

    $response->assertOk();
    expect($response->body['stash']['name'])->toBe('Renamed Stash')
        ->and($response->body['stash']['sync_mode'])->toBe('manual')
        ->and($response->body['stash']['download_policy'])->toBe($before->downloadPolicy->value);

    $after = StashRecord::findById(new PrimaryKey($stashId));
    expect($after->name)->toBe('Renamed Stash')
        ->and($after->syncMode->value)->toBe('manual');
});

test('PATCH stash rejects a blank name', function (): void {
    [$headers, $stashId] = $this->bootstrapFakeDownloadStash('patch-blank-name');

    $response = $this->http->patch('/api/v1/stashes/' . $stashId, [
        'name' => '   ',
    ], headers: $headers);

    $response->assertStatus(Status::BAD_REQUEST);
    expect($response->body['error']['code'])->toBe('validation_error');
});

test('PATCH stash rejects an unsupported sync_mode', function (): void {
    [$headers, $stashId] = $this->bootstrapFakeDownloadStash('patch-bad-sync-mode');

    $response = $this->http->patch('/api/v1/stashes/' . $stashId, [
        'sync_mode' => 'not-a-real-mode',
    ], headers: $headers);

    $response->assertStatus(Status::BAD_REQUEST);
    expect($response->body['error']['code'])->toBe('validation_error');
});

test('PATCH stash returns 404 for an unknown stash', function (): void {
    $headers = $this->authHeaders();

    $response = $this->http->patch('/api/v1/stashes/stash_01ARZ3NDEKTSV4RRFFQ69G5FAV', [
        'name' => 'Does not matter',
    ], headers: $headers);

    $response->assertStatus(Status::NOT_FOUND);
    expect($response->body['error']['code'])->toBe('not_found');
});

test('DELETE stash returns 404 for an unknown stash', function (): void {
    $headers = $this->authHeaders();

    $response = $this->http->delete('/api/v1/stashes/stash_01ARZ3NDEKTSV4RRFFQ69G5FAV', headers: $headers);

    $response->assertStatus(Status::NOT_FOUND);
    expect($response->body['error']['code'])->toBe('not_found');
});

test('DELETE stash cascades to stash items, inputs, and media item sources but leaves media items intact', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('delete-cascade');

    expect(StashItemRecord::select()->where('stashId', $stashId)->all())->not->toBeEmpty();
    $input = StashInputRecord::select()->where('stashId', $stashId)->first();
    expect($input)->not->toBeNull();
    expect(MediaItemSourceRecord::select()->where('stashInputId', (string) $input->id)->all())->not->toBeEmpty();

    $response = $this->http->delete('/api/v1/stashes/' . $stashId, headers: $headers);
    $response->assertOk();
    expect($response->body['deleted'])->toBeTrue();

    expect(StashRecord::findById(new PrimaryKey($stashId)))->toBeNull()
        ->and(StashItemRecord::select()->where('stashId', $stashId)->all())->toBeEmpty()
        ->and(StashInputRecord::select()->where('stashId', $stashId)->all())->toBeEmpty()
        ->and(MediaItemSourceRecord::select()->where('stashInputId', (string) $input->id)->all())->toBeEmpty();

    expect(\App\Vault\MediaItemRecord::findById(new PrimaryKey($mediaItemId)))->not->toBeNull();
});

test('DELETE stash rolls back cleanly instead of partially deleting when the transaction fails', function (): void {
    [$headers, $stashId] = $this->bootstrapFakeDownloadStash('delete-rollback');

    $itemCountBefore = count(StashItemRecord::select()->where('stashId', $stashId)->all());
    expect($itemCountBefore)->toBeGreaterThan(0);

    $realDatabase = $this->container->get(Database::class);
    $this->container->singleton(Database::class, new class ($realDatabase) implements Database {
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

                throw new RuntimeException('forced rollback for test');
            });
        }

        public function getRawSql(Query $query): ImmutableString
        {
            return $this->inner->getRawSql($query);
        }
    });

    $response = $this->http->delete('/api/v1/stashes/' . $stashId, headers: $headers);

    $response->assertStatus(Status::CONFLICT);
    expect($response->body['error']['code'])->toBe('delete_failed');

    expect(StashRecord::findById(new PrimaryKey($stashId)))->not->toBeNull()
        ->and(count(StashItemRecord::select()->where('stashId', $stashId)->all()))->toBe($itemCountBefore);
});

test('delete-impact reports media items shared with other stashes', function (): void {
    $headers = $this->authHeaders();

    $createStashForChannel = function (string $channel) use ($headers): string {
        $stash = $this->http->post('/api/v1/stashes', [
            'name' => $channel . '-' . bin2hex(random_bytes(3)),
        ], headers: $headers)->assertStatus(Status::CREATED);
        $stashId = $stash->body['stash']['id'];

        $preflight = $this->http->post('/api/v1/commands', [
            'type' => 'stash.preflight',
            'options' => ['source_uri' => 'fake://channel/' . $channel],
        ], headers: $headers)->assertStatus(Status::CREATED);
        $this->processAllJobs();

        $this->http->post('/api/v1/stashes/' . $stashId . '/inputs', [
            'preflight_command_id' => $preflight->body['command_id'],
        ], headers: $headers)->assertStatus(Status::CREATED);
        $this->processAllJobs();

        return $stashId;
    };

    $stashIdA = $createStashForChannel('shared-channel');
    $stashIdB = $createStashForChannel('shared-channel');

    $response = $this->http->get('/api/v1/stashes/' . $stashIdA . '/delete-impact', headers: $headers);
    $response->assertOk();

    $impact = $response->body['delete_impact'];
    expect($impact['shared_items'])->not->toBeEmpty()
        ->and($impact['orphaned_items'])->toBeEmpty();

    $sharedItem = $impact['shared_items'][0];
    expect($sharedItem['shared_with_stashes'])->toHaveCount(1)
        ->and($sharedItem['shared_with_stashes'][0]['id'])->toBe($stashIdB);
});

test('delete-impact reports orphaned media items when no other stash references them', function (): void {
    [$headers, $stashId] = $this->bootstrapFakeDownloadStash('orphan-channel');

    $response = $this->http->get('/api/v1/stashes/' . $stashId . '/delete-impact', headers: $headers);
    $response->assertOk();

    $impact = $response->body['delete_impact'];
    expect($impact['orphaned_items'])->not->toBeEmpty()
        ->and($impact['shared_items'])->toBeEmpty();
});

test('delete-impact returns 404 for an unknown stash', function (): void {
    $headers = $this->authHeaders();

    $response = $this->http->get('/api/v1/stashes/stash_01ARZ3NDEKTSV4RRFFQ69G5FAV/delete-impact', headers: $headers);

    $response->assertStatus(Status::NOT_FOUND);
    expect($response->body['error']['code'])->toBe('not_found');
});
