<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tempest\Database\PrimaryKey;
use Tempest\Http\Status;

use function Tempest\Mapper\map;

test('media server resources do not expose token secrets', function (): void {
    $headers = $this->authHeaders();
    $rawToken = 'resource-secret-token-' . bin2hex(random_bytes(6));

    $create = $this->http->post('/api/v1/media-servers', [
        'type' => 'jellyfin',
        'name' => 'Resource Jellyfin',
        'base_uri' => 'http://jellyfin.resource.test',
        'token' => $rawToken,
    ], headers: $headers)->assertStatus(Status::CREATED);
    $show = $this->http->get('/api/v1/media-servers/' . $create->body['media_server']['id'], headers: $headers)
        ->assertStatus(Status::OK);
    $json = json_encode([$create->body, $show->body], JSON_THROW_ON_ERROR);

    expect($json)->not->toContain($rawToken)
        ->and($json)->not->toContain('tokenSecretId')
        ->and($json)->not->toContain('token_secret_id')
        ->and($json)->not->toContain('encryptedValue')
        ->and($json)->not->toContain('encrypted_value');
});

test('auth user resources do not expose password hashes', function (): void {
    $headers = $this->authHeaders();

    $me = $this->http->get('/api/v1/auth/me', headers: $headers)->assertStatus(Status::OK);
    $json = json_encode($me->body, JSON_THROW_ON_ERROR);

    expect($json)->not->toContain('passwordHash')
        ->and($json)->not->toContain('password_hash')
        ->and($json)->not->toContain('$2y$');
});

test('#[Hidden] guards a record property from generic array mapping', function (): void {
    $headers = $this->authHeaders();
    $userId = $this->http->get('/api/v1/auth/me', headers: $headers)->assertStatus(Status::OK)->body['user']['id'];

    $user = \App\Auth\UserRecord::findById(new PrimaryKey($userId));

    expect(map($user)->toArray())->not->toHaveKey('passwordHash');
});

test('#[Hidden] excludes a property from the default record select', function (): void {
    $headers = $this->authHeaders();
    $userId = $this->http->get('/api/v1/auth/me', headers: $headers)->body['user']['id'];

    $user = \App\Auth\UserRecord::findById(new PrimaryKey($userId));

    expect(fn () => $user->passwordHash)->toThrow(\Tempest\Database\Exceptions\ValueWasMissing::class);
});

test('stash and vault list resources do not expose secret-shaped fields', function (): void {
    [$headers] = $this->bootstrapFakeDownloadStash('api-resource-stashes-list');

    $stashes = $this->http->get('/api/v1/stashes', headers: $headers)->assertStatus(Status::OK);
    $items = $this->http->get('/api/v1/items', headers: $headers)->assertStatus(Status::OK);
    $json = json_encode([$stashes->body, $items->body], JSON_THROW_ON_ERROR);

    expect($json)->not->toContain('tokenSecretId')
        ->and($json)->not->toContain('token_secret_id')
        ->and($json)->not->toContain('encryptedValue')
        ->and($json)->not->toContain('encrypted_value')
        ->and($json)->not->toContain('passwordHash')
        ->and($json)->not->toContain('password_hash');
});
