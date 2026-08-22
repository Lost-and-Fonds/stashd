<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Stashes\StashItemRecord;
use App\Vault\MediaItemRecord;
use Tempest\Database\PrimaryKey;
use Tempest\Http\Status;

test('preflight review exposes the universal title-regex filters for every provider', function (): void {
    $headers = $this->authHeaders();

    $preflight = $this->http->post('/api/v1/stashes/preflight', [
        'source_uri' => 'fake://channel/filters-universal',
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $review = $this->http->get(
        '/api/v1/stashes/preflight/' . $preflight->body['command_id'] . '/review',
        headers: $headers,
    )->assertOk();

    $keys = array_column($review->body['preflight']['universal_filters'], 'key');
    expect($keys)->toBe(['title_regex_include', 'title_regex_exclude'])
        ->and($review->body['preflight']['input_options'])->toBe([]);
});

test('preflight review exposes shorts and live toggles for a youtube channel only', function (): void {
    requireExternalInputPluginRuntime($this);
    $headers = $this->authHeaders();

    $channel = $this->http->post('/api/v1/stashes/preflight', [
        'source_uri' => 'https://www.youtube.com/channel/UCStashdDemoCh0012345678',
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $channelReview = $this->http->get(
        '/api/v1/stashes/preflight/' . $channel->body['command_id'] . '/review',
        headers: $headers,
    )->assertOk();

    $optionKeys = array_column($channelReview->body['preflight']['input_options'], 'key');
    expect($optionKeys)->toBe(['include_shorts', 'include_live', 'include_captions', 'caption_languages']);

    $video = $this->http->post('/api/v1/stashes/preflight', [
        'source_uri' => 'https://www.youtube.com/watch?v=demoVideo01',
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $videoReview = $this->http->get(
        '/api/v1/stashes/preflight/' . $video->body['command_id'] . '/review',
        headers: $headers,
    )->assertOk();

    expect($videoReview->body['preflight']['input_options'] ?? [])->toBe([]);
});

test('add input title-regex include keeps only matching items and marks the rest ignored', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', ['name' => 'Regex Include'], headers: $headers)->assertStatus(Status::CREATED);
    $stashId = $stash->body['stash']['id'];

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'fake://channel/regex-include'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $this->http->post('/api/v1/stashes/' . $stashId . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
        'options' => ['title_regex_include' => 'Episode 2'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $items = StashItemRecord::select()->where('stashId', $stashId)->all();
    expect($items)->toHaveCount(3);

    $byState = [];
    foreach ($items as $item) {
        $byState[$item->state->value][] = $item->ignoredReason;
    }

    expect($byState['active'] ?? [])->toHaveCount(1)
        ->and($byState['ignored'] ?? [])->toHaveCount(2)
        ->and(array_unique($byState['ignored']))->toBe(['filter_title_regex']);
});

test('add input title-regex exclude removes matching items and keeps the rest', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', ['name' => 'Regex Exclude'], headers: $headers)->assertStatus(Status::CREATED);
    $stashId = $stash->body['stash']['id'];

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'fake://channel/regex-exclude'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $this->http->post('/api/v1/stashes/' . $stashId . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
        'options' => ['title_regex_exclude' => 'Episode 1'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $items = StashItemRecord::select()->where('stashId', $stashId)->all();
    $byState = [];
    foreach ($items as $item) {
        $byState[$item->state->value][] = $item->ignoredReason;
    }

    expect($byState['active'] ?? [])->toHaveCount(2)
        ->and($byState['ignored'] ?? [])->toHaveCount(1)
        ->and($byState['ignored'][0])->toBe('filter_title_regex');
});

test('add input rejects a malformed title-regex pattern', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', ['name' => 'Bad Regex'], headers: $headers)->assertStatus(Status::CREATED);

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'fake://channel/bad-regex'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $response = $this->http->post('/api/v1/stashes/' . $stash->body['stash']['id'] . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
        'options' => ['title_regex_include' => '(unterminated'],
    ], headers: $headers);

    $response->assertStatus(Status::BAD_REQUEST);
    expect($response->body['error']['code'])->toBe('validation_error');
});

test('add input excludes shorts and live items from a youtube channel when toggled off', function (): void {
    $this->markTestSkipped('Provider-specific YouTube filter coverage belongs to the YouTube plugin suite.');
    requireExternalInputPluginRuntime($this);
    $headers = $this->authHeaders();

    $this->http->put('/api/v1/providers/youtube/credentials', [
        'api_key' => 'test-api-key',
    ], headers: $headers)->assertOk();

    $stash = $this->http->post('/api/v1/stashes', ['name' => 'No Shorts No Live'], headers: $headers)->assertStatus(Status::CREATED);
    $stashId = $stash->body['stash']['id'];

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'https://www.youtube.com/channel/UCStashdDemoCh0012345678'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $this->http->post('/api/v1/stashes/' . $stashId . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
        'options' => ['provider' => ['include_shorts' => false, 'include_live' => false]],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $items = StashItemRecord::select()->where('stashId', $stashId)->all();
    expect($items)->toHaveCount(18);

    $reasonsByMediaItemId = [];
    foreach ($items as $item) {
        $media = MediaItemRecord::findById(new PrimaryKey((string) $item->mediaItemId));
        $reasonsByMediaItemId[$media->providerItemId] = [$item->state->value, $item->ignoredReason];
    }

    expect($reasonsByMediaItemId['StashdVid01'])->toBe(['active', null])
        ->and($reasonsByMediaItemId['StashdVid15'])->toBe(['ignored', 'filter_video_type'])
        ->and($reasonsByMediaItemId['StashdVid16'])->toBe(['ignored', 'filter_video_type'])
        ->and($reasonsByMediaItemId['StashdVid17'])->toBe(['ignored', 'filter_video_type'])
        ->and($reasonsByMediaItemId['StashdVid18'])->toBe(['ignored', 'filter_video_type']);
});

test('PATCH stash input updates its stored title-regex filters', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', ['name' => 'Editable Filters'], headers: $headers)->assertStatus(Status::CREATED);
    $stashId = $stash->body['stash']['id'];

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'fake://channel/editable-filters'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $this->http->post('/api/v1/stashes/' . $stashId . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $inputId = $this->http->get('/api/v1/stashes/' . $stashId . '/inputs', headers: $headers)
        ->assertOk()->body['inputs'][0]['id'];

    $response = $this->http->patch('/api/v1/stashes/' . $stashId . '/inputs/' . $inputId, [
        'options' => ['title_regex_include' => 'Episode 2'],
    ], headers: $headers)->assertOk();

    expect($response->body['input']['options']['title_regex_include'])->toBe('Episode 2')
        ->and($response->body['input']['options']['title_regex_exclude'])->toBeNull();

    $refetched = $this->http->get('/api/v1/stashes/' . $stashId . '/inputs', headers: $headers)->assertOk();
    expect($refetched->body['inputs'][0]['options']['title_regex_include'])->toBe('Episode 2');
});

test('PATCH stash input with an empty options body clears stored filters', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', ['name' => 'Clear Filters'], headers: $headers)->assertStatus(Status::CREATED);
    $stashId = $stash->body['stash']['id'];

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'fake://channel/clear-filters'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $this->http->post('/api/v1/stashes/' . $stashId . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
        'options' => ['title_regex_include' => 'Episode 2'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $inputId = $this->http->get('/api/v1/stashes/' . $stashId . '/inputs', headers: $headers)
        ->assertOk()->body['inputs'][0]['id'];

    $response = $this->http->patch('/api/v1/stashes/' . $stashId . '/inputs/' . $inputId, [
        'options' => [],
    ], headers: $headers)->assertOk();

    expect($response->body['input']['options'])->toBeNull();
});

test('PATCH stash input rejects a malformed title-regex pattern', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', ['name' => 'Bad Edit Regex'], headers: $headers)->assertStatus(Status::CREATED);
    $stashId = $stash->body['stash']['id'];

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'fake://channel/bad-edit-regex'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $this->http->post('/api/v1/stashes/' . $stashId . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $inputId = $this->http->get('/api/v1/stashes/' . $stashId . '/inputs', headers: $headers)
        ->assertOk()->body['inputs'][0]['id'];

    $response = $this->http->patch('/api/v1/stashes/' . $stashId . '/inputs/' . $inputId, [
        'options' => ['title_regex_include' => '(unterminated'],
    ], headers: $headers);

    $response->assertStatus(Status::BAD_REQUEST);
    expect($response->body['error']['code'])->toBe('validation_error');
});

test('PATCH stash input re-filters already-committed items', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', ['name' => 'No Retroactive Filter'], headers: $headers)->assertStatus(Status::CREATED);
    $stashId = $stash->body['stash']['id'];

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'fake://channel/no-retroactive-filter'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $this->http->post('/api/v1/stashes/' . $stashId . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $inputId = $this->http->get('/api/v1/stashes/' . $stashId . '/inputs', headers: $headers)
        ->assertOk()->body['inputs'][0]['id'];

    // All 3 items committed active (no filter was set at add-input time).
    $beforeStates = array_map(
        static fn(StashItemRecord $item): string => $item->state->value,
        StashItemRecord::select()->where('stashId', $stashId)->all(),
    );
    expect($beforeStates)->toBe(['active', 'active', 'active']);

    $this->http->patch('/api/v1/stashes/' . $stashId . '/inputs/' . $inputId, [
        'options' => ['title_regex_include' => 'Episode 2'],
    ], headers: $headers)->assertOk();

    // Editing the filter applies to the items already committed from this input.
    $afterStates = array_map(
        static fn(StashItemRecord $item): string => $item->state->value,
        StashItemRecord::select()->where('stashId', $stashId)->all(),
    );
    expect($afterStates)->toBe(['ignored', 'active', 'ignored']);
});

test('PATCH stash input reactivates filtered YouTube items and queues their downloads', function (): void {
    $this->markTestSkipped('Provider-specific YouTube filter coverage belongs to the YouTube plugin suite.');
    requireExternalInputPluginRuntime($this);
    $headers = $this->authHeaders();
    $this->http->put('/api/v1/providers/youtube/credentials', ['api_key' => 'test-api-key'], headers: $headers)->assertOk();

    $stash = $this->http->post('/api/v1/stashes', ['name' => 'Enable YouTube Types'], headers: $headers)->assertStatus(Status::CREATED);
    $stashId = $stash->body['stash']['id'];
    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'https://www.youtube.com/channel/UCStashdDemoCh0012345678'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();
    $this->http->post('/api/v1/stashes/' . $stashId . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
        'options' => ['provider' => ['include_shorts' => false, 'include_live' => false]],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $inputs = $this->http->get('/api/v1/stashes/' . $stashId . '/inputs', headers: $headers)->assertOk();
    $inputId = $inputs->body['inputs'][0]['id'];
    expect(array_column($inputs->body['inputs'][0]['input_options'], 'key'))->toBe(['include_shorts', 'include_live']);
    $this->http->patch('/api/v1/stashes/' . $stashId . '/inputs/' . $inputId, [
        'options' => ['provider' => ['include_shorts' => true, 'include_live' => true]],
    ], headers: $headers)->assertOk();
    $this->processAllJobs();

    $items = StashItemRecord::select()->where('stashId', $stashId)->all();
    expect(array_map(static fn(StashItemRecord $item): string => $item->state->value, $items))->toBe(array_fill(0, 18, 'active'));
});

test('PATCH stash input returns 404 for an input belonging to a different stash', function (): void {
    $headers = $this->authHeaders();

    $stashA = $this->http->post('/api/v1/stashes', ['name' => 'Cross Stash A'], headers: $headers)->assertStatus(Status::CREATED);
    $stashB = $this->http->post('/api/v1/stashes', ['name' => 'Cross Stash B'], headers: $headers)->assertStatus(Status::CREATED);

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => ['source_uri' => 'fake://channel/cross-stash-input'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $this->http->post('/api/v1/stashes/' . $stashA->body['stash']['id'] . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $inputId = $this->http->get('/api/v1/stashes/' . $stashA->body['stash']['id'] . '/inputs', headers: $headers)
        ->assertOk()->body['inputs'][0]['id'];

    $this->http->patch('/api/v1/stashes/' . $stashB->body['stash']['id'] . '/inputs/' . $inputId, [
        'options' => ['title_regex_include' => 'Episode 2'],
    ], headers: $headers)->assertStatus(Status::NOT_FOUND);
});
