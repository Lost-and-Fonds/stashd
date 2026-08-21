<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tempest\Http\Status;

test('creating a podcast configured for video on a metadata-only stash warns without blocking', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', [
        'name' => 'Metadata Only Stash',
        'download_policy' => 'metadata_only',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $response = $this->http->post('/api/v1/stashes/' . $stash->body['stash']['id'] . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'My Podcast',
        'settings' => ['media_kind' => 'video'],
    ], headers: $headers);

    $response->assertStatus(Status::CREATED);
    expect($response->body['broadcast'])->not->toBeNull()
        ->and($response->body['policy_mismatch'])->not->toBeNull()
        ->and($response->body['policy_mismatch']['download_policy'])->toBe('metadata_only')
        ->and($response->body['policy_mismatch']['broadcast_type'])->toBe('podcast')
        ->and($response->body['policy_mismatch']['compatible_download_policies'])->toBe(['video', 'audio_only', 'manual_download']);
});

test('creating a podcast configured for video on an audio-only stash warns without blocking', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', [
        'name' => 'Audio Only Stash',
        'download_policy' => 'audio_only',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $response = $this->http->post('/api/v1/stashes/' . $stash->body['stash']['id'] . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'My Video Podcast',
        'settings' => ['media_kind' => 'video'],
    ], headers: $headers);

    $response->assertStatus(Status::CREATED);
    expect($response->body['policy_mismatch'])->toBeNull();
});

test('creating a podcast configured for audio on an audio-only stash has no mismatch', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', [
        'name' => 'Audio Only Stash 2',
        'download_policy' => 'audio_only',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $response = $this->http->post('/api/v1/stashes/' . $stash->body['stash']['id'] . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'My Audio Podcast',
        'settings' => ['media_kind' => 'audio'],
    ], headers: $headers);

    $response->assertStatus(Status::CREATED);
    expect($response->body['policy_mismatch'])->toBeNull();
});

test('creating a jellyfin series on an audio-only stash has no mismatch', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', [
        'name' => 'Audio Only Stash 3',
        'download_policy' => 'audio_only',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $response = $this->http->post('/api/v1/stashes/' . $stash->body['stash']['id'] . '/broadcasts', [
        'type' => 'jellyfin',
        'name' => 'My Series',
    ], headers: $headers);

    $response->assertStatus(Status::CREATED);
    expect($response->body['policy_mismatch'])->toBeNull();
});

test('creating any broadcast on a video-policy stash has no mismatch', function (): void {
    $headers = $this->authHeaders();

    $stash = $this->http->post('/api/v1/stashes', [
        'name' => 'Video Policy Stash',
    ], headers: $headers)->assertStatus(Status::CREATED);

    $response = $this->http->post('/api/v1/stashes/' . $stash->body['stash']['id'] . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'My Podcast',
        'settings' => ['media_kind' => 'video'],
    ], headers: $headers);

    $response->assertStatus(Status::CREATED);
    expect($response->body['policy_mismatch'])->toBeNull();
});
