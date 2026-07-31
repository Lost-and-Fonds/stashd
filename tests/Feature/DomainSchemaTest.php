<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Broadcasts\BroadcastRecord;
use App\Jobs\JobIntent;
use App\Jobs\JobLane;
use App\Jobs\JobRecord;
use App\Jobs\JobState;
use App\Stashes\StashInputRecord;
use App\Stashes\StashItemRecord;
use App\Stashes\StashRecord;
use App\Vault\MediaItemRecord;
use Tempest\Database\Builder\QueryBuilders\BuildsQuery;
use Tempest\Database\Builder\QueryBuilders\WhereGroupBuilder;
use Tempest\Database\Builder\WhereOperator;
use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Database;
use Tempest\Database\Direction;
use Tempest\Database\Query;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

test('domain schema migration creates all v1 tables on a fresh database', function (): void {
    $database = $this->container->get(Database::class);

    $tables = [
        'stashes',
        'stash_inputs',
        'stash_items',
        'media_items',
        'media_item_sources',
        'assets',
        'broadcasts',
        'broadcast_items',
        'broadcast_triggers',
        'broadcast_trigger_runs',
        'media_server_connections',
        'users',
        'api_tokens',
        'secrets',
    ];

    foreach ($tables as $table) {
        expect(schemaTableExists($database, $table))->toBeTrue("Expected table {$table} to exist.");
    }

    expect(schemaTableExists($database, 'raw_metadata_snapshots'))->toBeFalse();

    $jobColumns = schemaColumns($database, 'jobs');
    $stashColumns = schemaColumns($database, 'stashes');

    expect($jobColumns)->toContain('progressRate')
        ->and($jobColumns)->toContain('progressEtaSeconds')
        ->and($stashColumns)->not->toContain('slug');
});

test('media item provider identity is unique', function (): void {
    $repo = $this->container->get(\App\Vault\MediaItemRepository::class);

    $repo->create(
        providerKey: 'fake',
        providerItemId: 'dup-item',
        canonicalUri: 'fake://item/dup-item',
        title: 'First',
    );

    expect(fn () => $repo->create(
        providerKey: 'fake',
        providerItemId: 'dup-item',
        canonicalUri: 'fake://item/dup-item-2',
        title: 'Duplicate',
    ))->toThrow(\Tempest\Database\Exceptions\QueryWasInvalid::class);
});

test('stash item enforces stash and media item relationship uniqueness', function (): void {
    $stashes = $this->container->get(\App\Stashes\StashRepository::class);
    $media = $this->container->get(\App\Vault\MediaItemRepository::class);
    $items = $this->container->get(\App\Stashes\StashItemRepository::class);

    $stash = $stashes->create('Test Stash');
    $mediaItem = $media->create('fake', 'rel-item', 'fake://item/rel-item', 'Rel Item');

    $items->create(
        stashId: \App\Stashes\StashId::parse((string) $stash->id),
        mediaItemId: \App\Vault\MediaItemId::parse((string) $mediaItem->id),
    );

    expect(fn () => $items->create(
        stashId: \App\Stashes\StashId::parse((string) $stash->id),
        mediaItemId: \App\Vault\MediaItemId::parse((string) $mediaItem->id),
    ))->toThrow(\Tempest\Database\Exceptions\QueryWasInvalid::class);
});

test('job requires a valid command foreign key', function (): void {
    $jobs = $this->container->get(\App\Jobs\JobRepository::class);

    expect(fn () => $jobs->create(
        intent: \App\Jobs\JobIntent::Preflight,
        commandId: \App\Commands\CommandId::parse('cmd_01ARZ3NDEKTSV4RRFFQ69G5FAV'),
    ))->toThrow(\Tempest\Database\Exceptions\QueryWasInvalid::class);
});

test('job workload indexes support pending, stale, and history queries', function (): void {
    $database = $this->container->get(Database::class);

    expect(schemaIndexes($database, 'jobs'))->toContain(
        'jobs_pending_claim',
        'jobs_processing_heartbeat',
        'jobs_media_item_download_history',
    );

    $now = DateTime::now(Timezone::UTC);
    $pending = JobRecord::select()
        ->where('state', JobState::Pending)
        ->andWhereGroup(fn (WhereGroupBuilder $group) => $group
            ->whereNull('scheduledAt')
            ->orWhere('scheduledAt', $now, WhereOperator::LESS_THAN_OR_EQUAL))
        ->orderBy('priority', Direction::ASC)
        ->orderBy('createdAt', Direction::ASC)
        ->limit(5);
    $lane = JobRecord::select()
        ->where('state', JobState::Pending)
        ->andWhereGroup(fn (WhereGroupBuilder $group) => $group
            ->whereNull('scheduledAt')
            ->orWhere('scheduledAt', $now, WhereOperator::LESS_THAN_OR_EQUAL))
        ->orderBy('priority', Direction::ASC)
        ->orderBy('createdAt', Direction::ASC)
        ->limit(5)
        ->whereIn('intent', array_map(
            static fn (JobIntent $intent): string => $intent->value,
            JobLane::Bulk->intents(),
        ));
    $stale = JobRecord::select()
        ->where('state', JobState::Processing)
        ->whereNotNull('heartbeatAt')
        ->where('heartbeatAt', $now, WhereOperator::LESS_THAN);
    $history = JobRecord::select()
        ->where('entityType', 'media_item')
        ->where('intent', JobIntent::Download)
        ->whereIn('entityId', ['media_01ARZ3NDEKTSV4RRFFQ69G5FAV'])
        ->orderBy('createdAt', Direction::DESC)
        ->orderBy('id', Direction::DESC);

    // Every query must be valid SQL on the active dialect, whichever it is.
    foreach ([$pending, $lane, $stale, $history] as $builder) {
        expect($builder->all())->toBeArray();
    }

    // Plan assertions are SQLite-only on purpose: PostgreSQL's planner picks a
    // sequential scan on the empty test tables no matter which indexes exist,
    // so asserting index usage here would prove nothing and flake. Index
    // *existence* is asserted above on both dialects.
    if ($database->dialect !== DatabaseDialect::POSTGRESQL) {
        expect(jobQueryPlan($database, $pending))->toContain('jobs_pending_claim')
            ->not->toContain('SCAN jobs')
            ->and(jobQueryPlan($database, $lane))->toContain('jobs_pending_claim')
            ->not->toContain('SCAN jobs')
            ->and(jobQueryPlan($database, $stale))->toContain('jobs_processing_heartbeat')
            ->not->toContain('SCAN jobs')
            ->and(jobQueryPlan($database, $history))->toContain('jobs_media_item_download_history')
            ->not->toContain('SCAN jobs');
    }
});

test('broadcast belongs to stash via foreign key', function (): void {
    $stashes = $this->container->get(\App\Stashes\StashRepository::class);
    $broadcasts = $this->container->get(\App\Broadcasts\BroadcastRepository::class);

    $stash = $stashes->create('Broadcast Stash');
    $broadcast = $broadcasts->create(
        stashId: \App\Stashes\StashId::parse((string) $stash->id),
        type: 'podcast',
        name: 'Podcast',
        slug: 'podcast',
    );

    expect((string) $broadcast->stashId)->toBe((string) $stash->id);

    expect(fn () => $broadcasts->create(
        stashId: \App\Stashes\StashId::parse('stash_01ARZ3NDEKTSV4RRFFQ69G5FAV'),
        type: 'podcast',
        name: 'Orphan',
        slug: 'orphan',
    ))->toThrow(\Tempest\Database\Exceptions\QueryWasInvalid::class);
});

test('repository smoke creates stash with input media item stash item and broadcast', function (): void {
    $stashes = $this->container->get(\App\Stashes\StashRepository::class);
    $inputs = $this->container->get(\App\Stashes\StashInputRepository::class);
    $media = $this->container->get(\App\Vault\MediaItemRepository::class);
    $items = $this->container->get(\App\Stashes\StashItemRepository::class);
    $broadcasts = $this->container->get(\App\Broadcasts\BroadcastRepository::class);

    $stash = $stashes->create('Demo');
    $stashId = \App\Stashes\StashId::parse((string) $stash->id);

    $input = $inputs->create(
        stashId: $stashId,
        providerKey: 'fake',
        inputType: \App\Stashes\StashInputType::Channel,
        sourceUri: 'fake://channel/demo',
        providerInputId: 'channel:demo',
        title: 'Demo Channel',
    );

    $mediaItem = $media->create(
        providerKey: 'fake',
        providerItemId: 'demo-episode-1',
        canonicalUri: 'fake://item/demo-episode-1',
        title: 'Episode 1',
        durationSeconds: 600,
    );

    $stashItem = $items->create(
        stashId: $stashId,
        mediaItemId: \App\Vault\MediaItemId::parse((string) $mediaItem->id),
        stashInputId: \App\Stashes\StashInputId::parse((string) $input->id),
        position: 1,
    );

    $broadcast = $broadcasts->create(
        stashId: $stashId,
        type: 'jellyfin',
        name: 'Demo Series',
        slug: 'demo-series',
    );

    expect(StashRecord::findById($stash->id))->not->toBeNull()
        ->and(StashInputRecord::findById($input->id))->not->toBeNull()
        ->and(MediaItemRecord::findById($mediaItem->id))->not->toBeNull()
        ->and(StashItemRecord::findById($stashItem->id))->not->toBeNull()
        ->and(BroadcastRecord::findById($broadcast->id))->not->toBeNull()
        ->and((string) $stashItem->stashId)->toBe((string) $stash->id)
        ->and((string) $broadcast->stashId)->toBe((string) $stash->id);
});

function jobQueryPlan(Database $database, BuildsQuery $builder): string
{
    $query = $builder->build();
    $rows = $database->fetch(new Query('EXPLAIN QUERY PLAN ' . $query->compile(), $query->bindings));

    return implode("\n", array_column($rows, 'detail'));
}
