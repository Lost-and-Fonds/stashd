<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Commands\CommandRecord;
use App\Commands\CommandType;
use App\Providers\Fake\FakeProvider;
use App\Providers\ProviderRegistry;
use App\Stashes\StashId;
use App\Stashes\StashInputRepository;
use App\Stashes\StashItemRecord;
use App\Stashes\SyncMode;
use App\System\Scheduler\RoutineDiscoveryScheduler;
use Tempest\Http\Status;

/** @return list<CommandRecord> */
function commandsOfType(CommandType $type): array
{
    return CommandRecord::select()->where('type', $type)->all();
}

/** @return list<StashItemRecord> */
function itemsOf(string $stashId): array
{
    return StashItemRecord::select()->where('stashId', $stashId)->all();
}

test('a scheduled sync takes in new items and touches nothing when there are none', function (): void {
    $stashId = $this->bootstrapFakeChannelStash('sched-sync');
    expect(itemsOf($stashId))->toHaveCount(3);

    $inputs = $this->container->get(StashInputRepository::class);
    $scheduler = $this->container->get(RoutineDiscoveryScheduler::class);

    $makeDue = function () use ($inputs, $stashId): void {
        foreach ($inputs->listForStash(StashId::parse($stashId)) as $input) {
            $input->syncMode = SyncMode::Automatic;
            $input->nextCheckAt = null;
            $inputs->save($input);
        }
    };

    $rebuildsAfterAdd = count(commandsOfType(CommandType::BroadcastRebuild));

    // Nothing new upstream: the sync runs, finds nothing, and must leave the
    // stash -- and every broadcast belonging to it -- completely alone.
    $makeDue();
    expect($scheduler->runDueChecks())->toBe(1);
    $this->processAllJobs();

    expect(commandsOfType(CommandType::StashSyncInput))->toHaveCount(1)
        ->and(itemsOf($stashId))->toHaveCount(3)
        ->and(commandsOfType(CommandType::BroadcastRebuild))->toHaveCount($rebuildsAfterAdd);

    // A new episode appears upstream: it gets taken in.
    $provider = $this->container->get(ProviderRegistry::class)->get('fake');
    assert($provider instanceof FakeProvider);
    $provider->advanceSyncGeneration('channel:sched-sync');

    $makeDue();
    expect($scheduler->runDueChecks())->toBe(1);
    $this->processAllJobs();

    expect(commandsOfType(CommandType::StashSyncInput))->toHaveCount(2)
        ->and(itemsOf($stashId))->toHaveCount(4);

    // Preflight is a preview again -- syncing never routes through add-input.
    expect(commandsOfType(CommandType::StashAddInput))->toHaveCount(1);
});

test('a sync records input health and leaves positions a total order', function (): void {
    $stashId = $this->bootstrapFakeChannelStash('health-sync');
    $inputs = $this->container->get(StashInputRepository::class);

    $provider = $this->container->get(ProviderRegistry::class)->get('fake');
    assert($provider instanceof FakeProvider);
    $provider->advanceSyncGeneration('channel:health-sync');

    foreach ($inputs->listForStash(StashId::parse($stashId)) as $input) {
        $input->syncMode = SyncMode::Automatic;
        $input->nextCheckAt = null;
        $inputs->save($input);
    }

    $this->container->get(RoutineDiscoveryScheduler::class)->runDueChecks();
    $this->processAllJobs();

    $input = $inputs->listForStash(StashId::parse($stashId))[0];

    expect($input->lastSuccessAt)->not->toBeNull()
        ->and($input->lastCheckedAt)->not->toBeNull()
        ->and($input->lastFailureAt)->toBeNull()
        ->and($input->consecutiveFailures)->toBe(0);

    $positions = array_map(
        static fn (StashItemRecord $item): int => $item->position,
        itemsOf($stashId),
    );

    expect($positions)->toHaveCount(4)
        ->and(array_unique($positions))->toHaveCount(4);
});

test('the manual check endpoint syncs every input of the stash', function (): void {
    $stashId = $this->bootstrapFakeChannelStash('manual-sync');

    $provider = $this->container->get(ProviderRegistry::class)->get('fake');
    assert($provider instanceof FakeProvider);
    $provider->advanceSyncGeneration('channel:manual-sync');

    $response = $this->http->post('/api/v1/stashes/' . $stashId . '/sync', [], headers: $this->authHeaders())
        ->assertStatus(Status::ACCEPTED);
    expect($response->body['command_ids'])->toHaveCount(1);
    $this->processAllJobs();

    expect(itemsOf($stashId))->toHaveCount(4)
        ->and(commandsOfType(CommandType::StashSyncInput))->toHaveCount(1);
});

test('a second check while one is still queued does not run twice', function (): void {
    $stashId = $this->bootstrapFakeChannelStash('dedupe-sync');
    $headers = $this->authHeaders();

    $provider = $this->container->get(ProviderRegistry::class)->get('fake');
    assert($provider instanceof FakeProvider);
    $provider->advanceSyncGeneration('channel:dedupe-sync');

    // Two clicks before the worker gets to either one.
    $this->http->post('/api/v1/stashes/' . $stashId . '/sync', [], headers: $headers)
        ->assertStatus(Status::ACCEPTED);
    $this->http->post('/api/v1/stashes/' . $stashId . '/sync', [], headers: $headers)
        ->assertStatus(Status::ACCEPTED);

    $queued = \App\Jobs\JobRecord::select()->where('intent', \App\Jobs\JobIntent::SyncInput)->all();
    expect($queued)->toHaveCount(1);

    $this->processAllJobs();

    // The new item still lands exactly once, from the one job that ran.
    expect(itemsOf($stashId))->toHaveCount(4);
});

test('the manual check endpoint 404s for an unknown stash', function (): void {
    $this->http->post('/api/v1/stashes/stash_01JQZ0000000000000000000/sync', [], headers: $this->authHeaders())
        ->assertStatus(Status::NOT_FOUND);
});
