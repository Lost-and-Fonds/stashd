<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tempest\Http\Status;

test('activity endpoint requires authentication', function (): void {
    $this->authHeaders();

    $this->http->get('/api/v1/activity')->assertStatus(Status::UNAUTHORIZED);
});

test('creating a stash records a stash.created activity event surfaced via the activity endpoint', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', [
        'name' => 'Activity Test Stash',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $response = $this->http->get('/api/v1/activity', headers: $headers);
    $response->assertOk();

    $events = $response->body['events'];
    $created = array_values(array_filter($events, static fn(array $event): bool => $event['type'] === 'stash.created'
        && $event['stash_id'] === $stash->body['stash']['id']));

    expect($created)->toHaveCount(1)
        ->and($created[0]['message'])->toBe('Stash "Activity Test Stash" created.')
        ->and($created[0]['level'])->toBe('info');
});

test('activity events are returned newest first', function (): void {
    $headers = $this->authHeaders();

    $this->http->post('/api/v1/stashes', ['name' => 'First Stash'], headers: $headers)->assertStatus(Status::CREATED);
    $this->http->post('/api/v1/stashes', ['name' => 'Second Stash'], headers: $headers)->assertStatus(Status::CREATED);

    $response = $this->http->get('/api/v1/activity', headers: $headers)->assertOk();
    $events = $response->body['events'];

    $stashCreatedMessages = array_values(array_map(
        static fn(array $event): string => $event['message'],
        array_filter($events, static fn(array $event): bool => $event['type'] === 'stash.created'),
    ));

    expect($stashCreatedMessages[0])->toBe('Stash "Second Stash" created.')
        ->and($stashCreatedMessages[1])->toBe('Stash "First Stash" created.');
});
