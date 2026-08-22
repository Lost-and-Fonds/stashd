<?php

declare(strict_types=1);

namespace Tests\Feature;

test('broadcast plugins endpoint lists the discovered plugin keys', function (): void {
    $headers = $this->authHeaders();

    $response = $this->http->get('/api/v1/broadcast-plugins', headers: $headers);
    $response->assertOk();

    $keys = array_column($response->body['plugins'], 'key');

    expect($keys)->toContain('jellyfin', 'plex', 'podcast');

    $jellyfin = array_values(array_filter(
        $response->body['plugins'],
        static fn(array $plugin): bool => $plugin['key'] === 'jellyfin',
    ))[0] ?? null;

    expect($jellyfin)->not->toBeNull()
        ->and($jellyfin['label'])->toBeString()
        ->and($jellyfin['supported_file_kinds'])->toBe(['video']);
});
