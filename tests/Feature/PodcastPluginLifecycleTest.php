<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastPathBuilder;
use App\Broadcasts\BroadcastRepository;
use App\Vault\AssetKind;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\MediaItemId;
use App\Vault\MediaItemRecord;
use App\Vault\VaultPathBuilder;
use Tempest\Database\PrimaryKey;
use Tempest\Http\Status;

test('Podcast plugin is discovered and publishes an audio feed from PostgreSQL lifecycle', function (): void {
    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('podcast-lifecycle');
    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
    ], headers: $headers);
    $this->processAllJobs();

    $media = MediaItemRecord::findById(new PrimaryKey($mediaItemId));
    $audioPath = $this->container->get(VaultPathBuilder::class)->vaultFile(
        (string) $media->providerKey,
        (string) $media->providerItemId,
        'episode.wav',
    );

    if (! is_dir(dirname($audioPath))) {
        mkdir(dirname($audioPath), 0o775, true);
    }
    $samples = str_repeat("\0", 8000);
    $wav = 'RIFF' . pack('V', 36 + strlen($samples)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8) . 'data' . pack('V', strlen($samples)) . $samples;
    file_put_contents($audioPath, $wav);
    $this->container->get(AssetRepository::class)->create(
        mediaItemId: MediaItemId::parse($mediaItemId),
        role: AssetRole::VaultOriginal,
        kind: AssetKind::Audio,
        state: AssetState::Ready,
        path: $audioPath,
        relativePath: 'episode.wav',
        mimeType: 'audio/wav',
        container: 'wav',
        sizeBytes: filesize($audioPath) ?: null,
        checksum: hash_file('sha256', $audioPath) ?: null,
    );

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'podcast',
        'name' => 'Podcast Lifecycle',
        'slug' => 'podcast-lifecycle-' . bin2hex(random_bytes(3)),
        'settings' => [
            'title' => 'Lifecycle Podcast',
            'description' => 'Published from the application lifecycle.',
            'author' => 'Stashd',
            'media_kind' => 'audio',
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);

    $rebuild = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $rebuild->body['command_id'], headers: $headers);
    expect($command->body['command']['state'])->toBe('completed');

    $record = $this->container->get(BroadcastRepository::class)->find(BroadcastId::parse($broadcast->body['broadcast']['id']));
    $feedPath = $this->container->get(BroadcastPathBuilder::class)->broadcastFile($record, 'feed.xml');
    expect(is_file($feedPath))->toBeTrue()
        ->and((string) simplexml_load_file($feedPath)->channel->title)->toBe('Lifecycle Podcast');
});
