<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Broadcasts\BroadcastPathBuilder;
use App\Plugins\ExternalInputPluginRegistry;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\MediaItemId;
use App\Vault\MediaItemRecord;
use Tempest\Database\PrimaryKey;
use Tempest\Http\Status;

test('naked core runs filesystem input, vault promotion, filesystem broadcast, and jobs without providers', function (): void {
    if (getenv('STASHD_NAKED_CORE') === '1') {
        expect($this->container->get(ExternalInputPluginRegistry::class)->providers())->toBe([]);
    }

    [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash('naked-core');
    $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => ['media_item_id' => $mediaItemId, 'stash_id' => $stashId],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();
    $media = MediaItemRecord::findById(new PrimaryKey($mediaItemId));
    expect($media)->not->toBeNull();
    expect($this->container->get(AssetRepository::class)->findByMediaItemAndRole(MediaItemId::parse($mediaItemId), AssetRole::VaultOriginal))->not->toBeNull();

    $broadcast = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
        'type' => 'filesystem',
        'name' => 'Naked Core Files',
        'slug' => 'naked-core-' . bin2hex(random_bytes(3)),
    ], headers: $headers)->assertStatus(Status::CREATED);
    $rebuild = $this->http->post('/api/v1/commands', [
        'type' => 'broadcast.rebuild',
        'options' => ['broadcast_id' => $broadcast->body['broadcast']['id']],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $rebuild->body['command_id'], headers: $headers)->assertOk();
    expect($command->body['command']['state'])->toBe('completed');
    $record = $this->container->get(\App\Broadcasts\BroadcastRepository::class)->find(\App\Broadcasts\BroadcastId::parse($broadcast->body['broadcast']['id']));
    $path = $this->container->get(BroadcastPathBuilder::class)->broadcastRoot($record);
    expect(glob($path . '/Season 01/*') ?: [])->not->toBe([]);
});
