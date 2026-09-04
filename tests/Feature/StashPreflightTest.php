<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tempest\Http\Status;

test('stash preflight is synchronous and disposable', function (): void {
    $response = $this->http->post('/api/v1/stashes/preflight', [
        'source_uri' => 'fake://channel/preflight-demo',
        'source_title' => 'Preflight Demo Channel',
    ], headers: $this->authHeaders());

    $response->assertStatus(Status::OK);
    expect($response->body['preflight']['discovery']['estimated_item_count'])->toBe(3)
        ->and($response->body['preflight']['resolved_input']['provider_key'])->toBe('fake')
        ->and($response->body['preflight'])->not->toHaveKey('review_url')
        ->and($response->body['preflight'])->not->toHaveKey('origin');
});
