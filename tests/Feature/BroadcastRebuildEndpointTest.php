<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Commands\CommandRecord;
use App\Commands\CommandType;
use Tempest\Http\Status;

test('the broadcast rebuild endpoint dispatches the broadcast lifecycle command', function (): void {
    [$headers, $stashId] = array_slice($this->bootstrapFakeDownloadStash('broadcast-rebuild-endpoint'), 0, 2);
    $created = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'jellyfin',
        'name' => 'Endpoint rebuild',
        'slug' => 'endpoint-rebuild',
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $response = $this->http->post('/api/v1/broadcasts/' . $created->body['broadcast']['id'] . '/rebuild', [], headers: $headers)
        ->assertStatus(Status::ACCEPTED);

    $command = CommandRecord::findById(new \Tempest\Database\PrimaryKey($response->body['operation']['id']));

    expect($response->body['operation']['state'])->toBe('accepted')
        ->and($command)->not->toBeNull()
        ->and($command->type)->toBe(CommandType::BroadcastRebuild)
        ->and($command->targetType)->toBe('broadcast')
        ->and($command->targetId)->toBe($created->body['broadcast']['id']);

    $listed = $this->http->get('/api/v1/stashes/' . $stashId . '/broadcasts', headers: $headers)->assertOk();
    expect($listed->body['broadcasts'][0]['rebuild_operation']['id'])->toBe($response->body['operation']['id']);
});

test('the broadcast rebuild endpoint returns the active rebuild instead of enqueueing another one', function (): void {
    [$headers, $stashId] = array_slice($this->bootstrapFakeDownloadStash('broadcast-rebuild-dedupe'), 0, 2);
    $created = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'jellyfin',
        'name' => 'Dedupe rebuild',
        'slug' => 'dedupe-rebuild',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $first = $this->http->post('/api/v1/broadcasts/' . $created->body['broadcast']['id'] . '/rebuild', [], headers: $headers)
        ->assertStatus(Status::ACCEPTED);
    $second = $this->http->post('/api/v1/broadcasts/' . $created->body['broadcast']['id'] . '/rebuild', [], headers: $headers)
        ->assertStatus(Status::ACCEPTED);

    expect($second->body['operation']['id'])->toBe($first->body['operation']['id']);
});

test('the broadcast rebuild endpoint refuses an unknown broadcast', function (): void {
    $this->http->post('/api/v1/broadcasts/broadcast_01JQZ0000000000000000000/rebuild', [], headers: $this->authHeaders())
        ->assertStatus(Status::NOT_FOUND);
});
