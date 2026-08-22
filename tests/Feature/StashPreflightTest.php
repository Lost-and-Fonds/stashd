<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Commands\CommandRecord;
use App\Commands\CommandState;
use App\Commands\CommandType;
use App\Jobs\JobIntent;
use App\Jobs\JobRecord;
use App\Stashes\PreflightOrigin;
use Tempest\Database\Direction;
use Tempest\Http\Status;

test('stash preflight endpoint accepts command and completes after worker processing', function (): void {
    $headers = $this->authHeaders();

    $response = $this->http->post('/api/v1/stashes/preflight', [
        'source_uri' => 'fake://channel/preflight-demo',
        'source_title' => 'Preflight Demo Channel',
        'origin' => PreflightOrigin::CreateStash->value,
    ], headers: $headers);

    $response->assertStatus(Status::CREATED);
    expect($response->body['command_id'])->toStartWith('cmd_')
        ->and($response->body['command_state'])->toBe('accepted')
        ->and($response->body['job_ids'])->toHaveCount(1)
        ->and($response->body['review_url'])->toContain('/api/v1/stashes/preflight/')
        ->and($response->body['review_url'])->toContain('/review');

    $this->processAllJobs();

    $review = $this->http->get(
        '/api/v1/stashes/preflight/' . $response->body['command_id'] . '/review',
        headers: $headers,
    );
    $review->assertOk();
    expect($review->body['state'])->toBe('completed')
        ->and($review->body['preflight']['discovery']['estimated_item_count'])->toBe(3)
        ->and($review->body['preflight']['resolved_input']['provider_key'])->toBe('fake')
        ->and($review->body['ui_note'])->toContain('placeholder');
});

test('preflight persists completed command and ready job after worker run', function (): void {
    $headers = $this->authHeaders();

    $this->http->post('/api/v1/stashes/preflight', [
        'source_uri' => 'fake://playlist/preflight-list',
        'origin' => 'api',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $command = CommandRecord::select()
        ->where('type', CommandType::StashPreflight)
        ->orderBy('createdAt', Direction::DESC)
        ->first();

    expect($command)->not->toBeNull()
        ->and($command->state)->toBe(CommandState::Accepted);

    $this->processAllJobs();

    $command = CommandRecord::findById($command->id);
    expect($command->state->value)->toBe('completed')
        ->and($command->result)->not->toBeNull();

    $job = JobRecord::select()
        ->where('commandId', (string) $command->id)
        ->first();

    expect($job)->not->toBeNull()
        ->and($job->intent)->toBe(JobIntent::Preflight)
        ->and($job->state->value)->toBe('ready')
        ->and($job->progressTotal)->toBe(20);
});
