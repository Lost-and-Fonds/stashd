<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Stashes\StashItemRecord;
use App\Stashes\StashRecord;
use App\Vault\MediaItemRecord;
use Tempest\Http\Status;

test('youtube preflight command completes asynchronously using fixtures', function (): void {
    $headers = $this->authHeaders();

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => [
            'source_uri' => 'https://www.youtube.com/channel/UCStashdDemoCh0012345678',
            'source_title' => 'YouTube Demo Channel',
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $preflight->body['command_id'], headers: $headers);
    $command->assertOk();

    expect($command->body['command']['state'])->toBe('completed')
        ->and($command->body['command']['result']['resolved_input']['provider_key'])->toBe('youtube')
        ->and($command->body['command']['result']['discovery']['strategy_key'])->toBe('youtube.rss')
        ->and($command->body['command']['result']['discovery']['discovered_items'])->toHaveCount(3);

    $review = $this->http->get(
        '/api/v1/stashes/preflight/' . $preflight->body['command_id'] . '/review',
        headers: $headers,
    );
    $review->assertOk();
    expect($review->body['preflight']['discovery']['discovered_items'][0]['provider_item_id'])->toBe('demoVideo01');
});

test('youtube add input creates domain records without downloading', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', [
        'name' => 'YouTube Demo Stash',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => [
            'source_uri' => 'https://www.youtube.com/channel/UCStashdDemoCh0012345678',
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $add = $this->http->post('/api/v1/stashes/' . $stash->body['stash']['id'] . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $add->body['command_id'], headers: $headers);
    $command->assertOk();

    expect($command->body['command']['state'])->toBe('completed')
        ->and($command->body['command']['result']['media_items_created'])->toBe(3)
        ->and($command->body['command']['result']['stash_items_created'])->toBe(3);

    $media = MediaItemRecord::select()->where('providerKey', 'youtube')->all();
    expect($media)->toHaveCount(3)
        ->and($media[0]->thumbnailUri)->not->toBeNull();
});

test('youtube playlist input uses the playlist title', function (): void {
    $headers = $this->authHeaders();
    $stash = $this->http->post('/api/v1/stashes', ['name' => 'Playlist Stash'], headers: $headers)->assertStatus(Status::CREATED);

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'https://www.youtube.com/playlist?list=PLStashdDemoPlaylist01'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $add = $this->http->post('/api/v1/stashes/' . $stash->body['stash']['id'] . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $input = \App\Stashes\StashInputRecord::findById(new \Tempest\Database\PrimaryKey(
        $this->http->get('/api/v1/commands/' . $add->body['command_id'], headers: $headers)->body['command']['result']['stash_input_id'],
    ));

    expect($input?->title)->toBe('Stashd Demo Playlist');
});

test('youtube media items deduplicate across multiple stashes', function (): void {
    $headers = $this->authHeaders();
    $mediaItems = $this->container->get(\App\Vault\MediaItemRepository::class);

    $mediaItems->create(
        providerKey: 'youtube',
        providerItemId: 'demoVideo01',
        canonicalUri: 'https://www.youtube.com/watch?v=demoVideo01',
        title: 'Existing YouTube Item',
    );

    $stash = $this->http->post('/api/v1/stashes', [
        'name' => 'YouTube Dedupe Stash',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => [
            'source_uri' => 'https://www.youtube.com/channel/UCStashdDemoCh0012345678',
        ],
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
        ->and(MediaItemRecord::count()->execute())->toBe(3)
        ->and(StashRecord::count()->execute())->toBe(1)
        ->and(StashItemRecord::count()->execute())->toBe(3);
});

test('youtube preflight review exposes resolved channel identity for a handle source', function (): void {
    $headers = $this->authHeaders();

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => [
            'source_uri' => 'https://www.youtube.com/@StashdDemo',
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $review = $this->http->get(
        '/api/v1/stashes/preflight/' . $preflight->body['command_id'] . '/review',
        headers: $headers,
    );
    $review->assertOk();

    expect($review->body['preflight']['resolved_input']['provider_input_id'])->toBe('UCStashdDemoCh0012345678')
        ->and($review->body['preflight']['resolved_input']['source_title'])->toBe('Stashd Demo')
        ->and($review->body['preflight']['resolved_input']['source_avatar_uri'])->toBe('https://yt3.googleusercontent.com/stashd-demo-avatar.jpg')
        ->and($review->body['preflight']['resolved_input']['estimated_item_count'])->toBe(217);
});

test('youtube add input populates stash icon_uri from the resolved channel avatar when unset', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', [
        'name' => 'YouTube Handle Icon Stash',
    ], headers: $headers)->assertStatus(Status::CREATED);
    expect(StashRecord::findById(new \Tempest\Database\PrimaryKey($stash->body['stash']['id']))->iconUri)->toBeNull();

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => [
            'source_uri' => 'https://www.youtube.com/@StashdDemo',
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $add = $this->http->post('/api/v1/stashes/' . $stash->body['stash']['id'] . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $add->body['command_id'], headers: $headers);
    $command->assertOk();
    expect($command->body['command']['state'])->toBe('completed');

    $updatedStash = StashRecord::findById(new \Tempest\Database\PrimaryKey($stash->body['stash']['id']));
    expect($updatedStash)->not->toBeNull()
        ->and($updatedStash->iconUri)->toBe('https://yt3.googleusercontent.com/stashd-demo-avatar.jpg')
        ->and($updatedStash->name)->toBe('YouTube Handle Icon Stash');

    $show = $this->http->get('/api/v1/stashes/' . $stash->body['stash']['id'], headers: $headers)->assertOk();
    expect($show->body['stash']['icon_uri'])->toBe('https://yt3.googleusercontent.com/stashd-demo-avatar.jpg');
});

test('youtube add input defaults the stash name to the resolved channel title when no name was given', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', [], headers: $headers)->assertStatus(Status::CREATED);
    expect($stash->body['stash']['name'])->toBe('New Stash');

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => [
            'source_uri' => 'https://www.youtube.com/@StashdDemo',
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $add = $this->http->post('/api/v1/stashes/' . $stash->body['stash']['id'] . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $updatedStash = StashRecord::findById(new \Tempest\Database\PrimaryKey($stash->body['stash']['id']));
    expect($updatedStash)->not->toBeNull()
        ->and($updatedStash->name)->toBe('Stashd Demo');
});

test('a second input never overwrites a stash name already defaulted from the first', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', [], headers: $headers)->assertStatus(Status::CREATED);
    $stashId = $stash->body['stash']['id'];

    $firstPreflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => [
            'source_uri' => 'https://www.youtube.com/@StashdDemo',
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();
    $this->http->post('/api/v1/stashes/' . $stashId . '/inputs', [
        'preflight_command_id' => $firstPreflight->body['command_id'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $afterFirst = StashRecord::findById(new \Tempest\Database\PrimaryKey($stashId));
    expect($afterFirst->name)->toBe('Stashd Demo');

    $secondPreflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => [
            'source_uri' => 'fake://channel/second-source',
            'source_title' => 'A Totally Different Channel',
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();
    $this->http->post('/api/v1/stashes/' . $stashId . '/inputs', [
        'preflight_command_id' => $secondPreflight->body['command_id'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $afterSecond = StashRecord::findById(new \Tempest\Database\PrimaryKey($stashId));
    expect($afterSecond->name)->toBe('Stashd Demo');
});

test('unsupported youtube url fails preflight job with stable error', function (): void {
    $headers = $this->authHeaders();

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => [
            'source_uri' => 'https://www.youtube.com/feed/trending',
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $preflight->body['command_id'], headers: $headers);
    $command->assertOk();

    expect($command->body['command']['state'])->toBe('failed');

    $jobId = $command->body['jobs'][0]['id'];
    $job = $this->http->get('/api/v1/jobs/' . $jobId, headers: $headers);
    $job->assertOk();
    expect($job->body['job']['state'])->toBe('failed')
        ->and($job->body['job']['last_error'])->toContain('Unsupported YouTube URL');
});
