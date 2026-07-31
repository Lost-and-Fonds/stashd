<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastItemRecord;
use App\Broadcasts\BroadcastItemRepository;
use App\Broadcasts\BroadcastRepository;
use App\Broadcasts\Podcasts\PodcastTokenDigest;
use App\Broadcasts\Podcasts\PodcastTokenService;
use App\Stashes\StashItemId;
use App\Stashes\StashItemRecord;
use App\System\Activity\ActivityEventRecord;
use App\System\Secret\SecretRecord;
use App\System\Secret\SecretRepository;
use App\System\Secret\SecretsService;
use App\System\Secret\SecretType;
use App\Vault\MediaItemId;
use Tempest\Database\Database;
use Tempest\Database\PrimaryKey;
use Tempest\Http\Status;

test('podcast token migration adds broadcast item token columns', function (): void {
    $database = $this->container->get(Database::class);
    $columns = schemaColumns($database, 'broadcast_items');

    expect($columns)->toContain('tokenSecretId')
        ->and($columns)->toContain('tokenPreview');
});

test('podcast token migration adds an indexed blind digest column', function (): void {
    $database = $this->container->get(Database::class);
    $columns = schemaColumns($database, 'secrets');
    $indexes = schemaIndexes($database, 'secrets');

    expect($columns)->toContain('tokenDigest')
        ->and($indexes)->toContain('secrets_token_digest');
});

test('broadcast item record maps podcast token columns', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('podcast-item-record');
    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Audio Podcast',
        'slug' => 'audio-podcast-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);

    $stashItem = StashItemRecord::select()->where('stashId', $stashId)->first();
    $item = $this->container->get(BroadcastItemRepository::class)->create(
        broadcastId: BroadcastId::parse($broadcast->body['broadcast']['id']),
        stashItemId: StashItemId::parse((string) $stashItem->id),
        mediaItemId: MediaItemId::parse($mediaItemId),
    );
    $this->container->get(SecretsService::class)->put(
        'test.broadcast_item_record_token',
        SecretType::BroadcastToken,
        'record-token',
    );
    $secret = $this->container->get(SecretRepository::class)->findByKey('test.broadcast_item_record_token');

    $item->tokenSecretId = (string) $secret->id;
    $item->tokenPreview = 'abcd...uvwxyz';
    $this->container->get(BroadcastItemRepository::class)->save($item);

    $reloaded = BroadcastItemRecord::select()
        ->include('tokenSecretId')
        ->get(new PrimaryKey((string) $item->id));

    expect($reloaded?->tokenSecretId)->toBe((string) $secret->id)
        ->and($reloaded?->tokenPreview)->toBe('abcd...uvwxyz');
});

test('creating podcast broadcasts exposes authenticated feed urls', function (string $mediaKind): void {
    [$headers, $stashId] = array_slice($this->bootstrapFakeDownloadStash('podcast-create-' . $mediaKind), 0, 2);

    $create = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Private Podcast',
        'slug' => 'podcast-' . $mediaKind . '-' . bin2hex(random_bytes(3)),
        'settings' => ['media_kind' => $mediaKind],
    ], headers: $headers)->assertStatus(Status::CREATED);

    expect($create->body['broadcast']['feed_url'])->toStartWith('http://localhost:8474/b/')
        ->and($create->body['broadcast']['feed_url'])->toEndWith('/feed.xml')
        ->and($create->body['broadcast']['feed_url'])->not->toContain('?')
        ->and($create->body['broadcast']['token_preview'])->toContain('...');

    $show = $this->http->get('/api/v1/broadcasts/' . $create->body['broadcast']['id'], headers: $headers);

    expect($show->body['broadcast']['feed_url'])->toBe($create->body['broadcast']['feed_url'])
        ->and($show->body['broadcast']['token_preview'])->toBe($create->body['broadcast']['token_preview']);
})->with(['audio', 'video']);

test('podcast broadcast token is encrypted and reconstructed after database reload', function (): void {
    [$headers, $stashId] = array_slice($this->bootstrapFakeDownloadStash('podcast-secret'), 0, 2);

    $create = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Secret Podcast',
        'slug' => 'secret-podcast-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);

    $feedUrl = $create->body['broadcast']['feed_url'];
    $token = podcastTokenFromFeedUrl($feedUrl);
    $broadcast = $this->container->get(BroadcastRepository::class)
        ->find(BroadcastId::parse($create->body['broadcast']['id']));
    $secret = SecretRecord::select()
        ->include('encryptedValue')
        ->get(new PrimaryKey((string) $broadcast->tokenSecretId));

    expect($broadcast?->tokenPreview)->not->toBe($token)
        ->and($broadcast?->tokenPreview)->not->toContain($token)
        ->and(json_encode($broadcast))->not->toContain($token)
        ->and($secret?->encryptedValue)->not->toContain($token)
        ->and($this->container->get(SecretsService::class)->get($secret->key))->toBe($token);

    $show = $this->http->get('/api/v1/broadcasts/' . $create->body['broadcast']['id'], headers: $headers);
    expect($show->body['broadcast']['feed_url'])->toBe($feedUrl);
});

test('podcast item token is generated and stored encrypted', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('podcast-item-token');
    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Item Token Podcast',
        'slug' => 'item-token-podcast-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);

    $stashItem = StashItemRecord::select()->where('stashId', $stashId)->first();
    $item = $this->container->get(BroadcastItemRepository::class)->create(
        broadcastId: BroadcastId::parse($broadcast->body['broadcast']['id']),
        stashItemId: StashItemId::parse((string) $stashItem->id),
        mediaItemId: MediaItemId::parse($mediaItemId),
    );

    $token = $this->container->get(PodcastTokenService::class)->ensureItemToken($item);
    $reloaded = BroadcastItemRecord::select()
        ->include('tokenSecretId')
        ->get(new PrimaryKey((string) $item->id));
    $secret = SecretRecord::select()
        ->include('encryptedValue')
        ->get(new PrimaryKey((string) $reloaded->tokenSecretId));

    expect($reloaded?->tokenPreview)->not->toBe($token)
        ->and($reloaded?->tokenPreview)->not->toContain($token)
        ->and($secret?->encryptedValue)->not->toContain($token)
        ->and($this->container->get(SecretsService::class)->get($secret->key))->toBe($token);
});

test('podcast token lookup decrypts and records use for only the indexed candidate', function (): void {
    [$headers, $stashId] = array_slice($this->bootstrapFakeDownloadStash('podcast-indexed-lookup'), 0, 2);

    $first = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'First Indexed Podcast',
        'slug' => 'first-indexed-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);
    $second = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Second Indexed Podcast',
        'slug' => 'second-indexed-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);

    $broadcasts = $this->container->get(BroadcastRepository::class);
    $secrets = $this->container->get(SecretRepository::class);
    $firstRecord = $broadcasts->find(BroadcastId::parse($first->body['broadcast']['id']))
        ?? throw new \RuntimeException('First podcast broadcast was not persisted.');
    $secondRecord = $broadcasts->find(BroadcastId::parse($second->body['broadcast']['id']))
        ?? throw new \RuntimeException('Second podcast broadcast was not persisted.');
    $firstSecretRecord = SecretRecord::findById(new PrimaryKey($firstRecord->tokenSecretId))
        ?? throw new \RuntimeException('First podcast token secret was not persisted.');
    $secondSecretRecord = SecretRecord::findById(new PrimaryKey($secondRecord->tokenSecretId))
        ?? throw new \RuntimeException('Second podcast token secret was not persisted.');
    $firstSecret = $secrets->findByKey($firstSecretRecord->key)
        ?? throw new \RuntimeException('First podcast token secret could not be loaded.');
    $secondSecret = $secrets->findByKey($secondSecretRecord->key)
        ?? throw new \RuntimeException('Second podcast token secret could not be loaded.');
    $firstSecret->lastUsedAt = null;
    $secondSecret->lastUsedAt = null;
    $secrets->save($firstSecret);
    $secrets->save($secondSecret);

    $token = podcastTokenFromFeedUrl($first->body['broadcast']['feed_url']);
    $resolved = $this->container->get(PodcastTokenService::class)->findPodcastBroadcastByFeedToken($token);
    $firstReloaded = $secrets->findByKey($firstSecret->key);
    $secondReloaded = $secrets->findByKey($secondSecret->key);

    expect((string) $resolved?->id)->toBe($first->body['broadcast']['id'])
        ->and($firstReloaded?->tokenDigest)->toBe($this->container->get(PodcastTokenDigest::class)->for($token))
        ->and($firstReloaded?->lastUsedAt)->not->toBeNull()
        ->and($secondReloaded?->lastUsedAt)->toBeNull();
});

test('podcast token digest backfill is idempotent', function (): void {
    [$headers, $stashId] = array_slice($this->bootstrapFakeDownloadStash('podcast-digest-backfill'), 0, 2);
    $created = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Backfilled Podcast',
        'slug' => 'backfilled-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);
    $broadcast = $this->container->get(BroadcastRepository::class)
        ->find(BroadcastId::parse($created->body['broadcast']['id']))
        ?? throw new \RuntimeException('Podcast broadcast was not persisted.');
    $secret = SecretRecord::findById(new PrimaryKey($broadcast->tokenSecretId))
        ?? throw new \RuntimeException('Podcast token secret was not persisted.');
    $secrets = $this->container->get(SecretRepository::class);
    $record = $secrets->findByKey($secret->key)
        ?? throw new \RuntimeException('Podcast token secret could not be loaded.');
    $record->tokenDigest = null;
    $secrets->save($record);

    $tokens = $this->container->get(PodcastTokenService::class);
    $tokens->backfillMissingTokenDigests();
    $backfilled = $secrets->findByKey($record->key)
        ?? throw new \RuntimeException('Backfilled podcast token secret could not be loaded.');
    $updatedAt = (string) $backfilled->updatedAt;
    $tokens->backfillMissingTokenDigests();
    $again = $secrets->findByKey($record->key)
        ?? throw new \RuntimeException('Backfilled podcast token secret could not be reloaded.');

    expect($backfilled->tokenDigest)->not->toBeNull()
        ->and((string) $again->updatedAt)->toBe($updatedAt);
});

test('broadcast rotate token changes feed url and stores only safe command metadata', function (): void {
    [$headers, $stashId] = array_slice($this->bootstrapFakeDownloadStash('podcast-rotate'), 0, 2);
    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Rotating Podcast',
        'slug' => 'rotating-podcast-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);

    $oldUrl = $broadcast->body['broadcast']['feed_url'];
    $oldToken = podcastTokenFromFeedUrl($oldUrl);
    $oldRecord = $this->container->get(BroadcastRepository::class)
        ->find(BroadcastId::parse($broadcast->body['broadcast']['id']));
    $oldSecretId = $oldRecord->tokenSecretId;

    $rotate = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rotate_token',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $showCommand = $this->http->get('/api/v1/commands/' . $rotate->body['command_id'], headers: $headers);
    $showBroadcast = $this->http->get('/api/v1/broadcasts/' . $broadcast->body['broadcast']['id'], headers: $headers);
    $newUrl = $showBroadcast->body['broadcast']['feed_url'];
    $newToken = podcastTokenFromFeedUrl($newUrl);
    $oldSecret = SecretRecord::findById(new PrimaryKey($oldSecretId));

    expect($showCommand->body['command']['state'])->toBe('completed')
        ->and($newUrl)->not->toBe($oldUrl)
        ->and($oldSecret?->revokedAt)->not->toBeNull()
        ->and(json_encode($showCommand->body['command']['options']))->not->toContain($oldToken)
        ->and(json_encode($showCommand->body['command']['options']))->not->toContain($newToken)
        ->and(json_encode($showCommand->body['command']['result']))->not->toContain($oldToken)
        ->and(json_encode($showCommand->body['command']['result']))->not->toContain($newToken)
        ->and($showCommand->body['command']['result']['token']['rotated'])->toBeTrue();

    $activity = ActivityEventRecord::select()
        ->where('type', 'broadcast.token_rotated')
        ->orderBy('createdAt', \Tempest\Database\Direction::DESC)
        ->first();

    expect(json_encode($activity))->not->toContain($oldToken)
        ->and(json_encode($activity))->not->toContain($newToken);
});

test('non podcast broadcasts do not expose feed url and cannot rotate tokens', function (): void {
    [$headers, $stashId, $mediaItemId, $broadcastId] = $this->bootstrapFakeDownloadBroadcast('podcast-nonpodcast');

    $show = $this->http->get('/api/v1/broadcasts/' . $broadcastId, headers: $headers);

    expect($show->body['broadcast'])->not->toHaveKey('feed_url')
        ->and($show->body['broadcast'])->not->toHaveKey('token_preview');

    $rotate = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rotate_token',
        'options' => ['broadcast_id' => $broadcastId],
    ], headers: $headers)->assertStatus(Status::BAD_REQUEST);

    expect(json_encode($rotate->body))->not->toContain('token=');

    unset($stashId, $mediaItemId);
});

function podcastTokenFromFeedUrl(string $feedUrl): string
{
    $path = parse_url($feedUrl, PHP_URL_PATH);
    expect($path)->not->toBeFalse();
    $parts = explode('/', trim((string) $path, '/'));

    return $parts[1] ?? '';
}
