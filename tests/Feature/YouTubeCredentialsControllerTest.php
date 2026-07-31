<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\System\Secret\SecretRecord;
use Tempest\Http\Status;

test('youtube credentials endpoint reports unconfigured by default', function (): void {
    $headers = $this->authHeaders();

    $response = $this->http->get('/api/v1/providers/youtube/credentials', headers: $headers)
        ->assertOk();

    expect($response->body['configured'])->toBeFalse();
});

test('youtube credentials endpoint stores the key and never echoes it back', function (): void {
    $headers = $this->authHeaders();

    $update = $this->http->put('/api/v1/providers/youtube/credentials', [
        'api_key' => 'AIzaSyTestOnlyFixtureKeyValue',
    ], headers: $headers)->assertOk();

    expect($update->body)->toBe(['configured' => true])
        ->and(json_encode($update->body))->not->toContain('AIzaSyTestOnlyFixtureKeyValue');

    $show = $this->http->get('/api/v1/providers/youtube/credentials', headers: $headers)->assertOk();
    expect($show->body['configured'])->toBeTrue();

    $secret = SecretRecord::select()->where('key', 'youtube_data_api_key')->first();
    expect($secret)->not->toBeNull();
});

test('youtube credentials endpoint rejects an empty key', function (): void {
    $headers = $this->authHeaders();

    $this->http->put('/api/v1/providers/youtube/credentials', [
        'api_key' => '   ',
    ], headers: $headers)->assertStatus(Status::BAD_REQUEST);
});

test('youtube credentials endpoint requires authentication', function (): void {
    $this->authHeaders();

    $this->http->get('/api/v1/providers/youtube/credentials')->assertStatus(Status::UNAUTHORIZED);
});
