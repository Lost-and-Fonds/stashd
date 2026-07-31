<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastItemRepository;
use App\Broadcasts\BroadcastRepository;
use App\Broadcasts\Podcasts\PodcastTokenService;
use App\Stashes\StashItemId;
use App\Stashes\StashItemRecord;
use App\System\Secret\SecretRecord;
use App\Vault\MediaItemId;
use Tempest\Database\PrimaryKey;
use Tempest\Http\Status;

test('podcast broadcast resources expose only intended token fields', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('api-resource-podcast');

    $create = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'API Resource Podcast',
        'slug' => 'api-resource-podcast-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);

    $feedToken = apiResourcePodcastTokenFromFeedUrl($create->body['broadcast']['feed_url']);
    $broadcast = $this->container->get(BroadcastRepository::class)
        ->find(BroadcastId::parse($create->body['broadcast']['id']));
    $secret = SecretRecord::select()
        ->include('encryptedValue')
        ->get(new PrimaryKey((string) $broadcast->tokenSecretId));
    $json = json_encode($create->body, JSON_THROW_ON_ERROR);

    expect($create->body['broadcast'])->toHaveKey('feed_url')
        ->and($create->body['broadcast'])->toHaveKey('token_preview')
        ->and($create->body['broadcast']['feed_url'])->toContain($feedToken)
        ->and($json)->not->toContain('tokenSecretId')
        ->and($json)->not->toContain('token_secret_id')
        ->and($json)->not->toContain((string) $broadcast->tokenSecretId)
        ->and($json)->not->toContain((string) $secret?->encryptedValue);

    $stashItem = StashItemRecord::select()->where('stashId', $stashId)->first();
    $item = $this->container->get(BroadcastItemRepository::class)->create(
        broadcastId: BroadcastId::parse($create->body['broadcast']['id']),
        stashItemId: StashItemId::parse((string) $stashItem->id),
        mediaItemId: MediaItemId::parse($mediaItemId),
    );
    $itemToken = $this->container->get(PodcastTokenService::class)->ensureItemToken($item);

    $items = $this->http->get('/api/v1/broadcasts/' . $create->body['broadcast']['id'] . '/items', headers: $headers)
        ->assertStatus(Status::OK);
    $itemsJson = json_encode($items->body, JSON_THROW_ON_ERROR);

    expect($items->body['items'][0])->toHaveKey('token_preview')
        ->and($itemsJson)->not->toContain($itemToken)
        ->and($itemsJson)->not->toContain('tokenSecretId')
        ->and($itemsJson)->not->toContain('token_secret_id');
});

test('non podcast broadcast resources do not expose podcast feed fields', function (): void {
    [$headers, , , $broadcastId] = $this->bootstrapFakeDownloadBroadcast('api-resource-non-podcast');

    $show = $this->http->get('/api/v1/broadcasts/' . $broadcastId, headers: $headers)
        ->assertStatus(Status::OK);

    expect($show->body['broadcast'])->not->toHaveKey('feed_url')
        ->and($show->body['broadcast'])->not->toHaveKey('token_preview');
});

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

    expect(\Tempest\Mapper\map($user)->toArray())->not->toHaveKey('passwordHash');
});

test('#[Hidden] excludes a property from the default record select', function (): void {
    $headers = $this->authHeaders();
    $userId = $this->http->get('/api/v1/auth/me', headers: $headers)->body['user']['id'];

    // Loaded via the plain default select (no ->include('passwordHash')) --
    // this is the guardrail actually doing something, not just the attribute
    // being present: reading the hidden property must fail because it was
    // never hydrated by the query.
    $user = \App\Auth\UserRecord::findById(new PrimaryKey($userId));

    expect(fn () => $user->passwordHash)->toThrow(\Tempest\Database\Exceptions\ValueWasMissing::class);
});

test('command and job resources do not expose raw podcast tokens', function (): void {
    [$headers, $stashId] = array_slice($this->bootstrapFakeDownloadStash('api-resource-command-token'), 0, 2);
    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Command Token Podcast',
        'slug' => 'command-token-podcast-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);

    $oldToken = apiResourcePodcastTokenFromFeedUrl($broadcast->body['broadcast']['feed_url']);
    $rotate = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rotate_token',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $show = $this->http->get('/api/v1/commands/' . $rotate->body['command_id'], headers: $headers)
        ->assertStatus(Status::OK);
    $newUrl = $this->http->get('/api/v1/broadcasts/' . $broadcast->body['broadcast']['id'], headers: $headers)
        ->assertStatus(Status::OK)
        ->body['broadcast']['feed_url'];
    $newToken = apiResourcePodcastTokenFromFeedUrl($newUrl);
    $json = json_encode($show->body, JSON_THROW_ON_ERROR);

    expect($json)->not->toContain($oldToken)
        ->and($json)->not->toContain($newToken)
        ->and($show->body['command']['options'])->toHaveKey('broadcast_id')
        ->and($show->body['jobs'][0]['payload'])->toHaveKey('broadcast_id');
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

function apiResourcePodcastTokenFromFeedUrl(string $feedUrl): string
{
    $path = parse_url($feedUrl, PHP_URL_PATH);
    $parts = explode('/', trim((string) $path, '/'));

    return $parts[1] ?? '';
}
