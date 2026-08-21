<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Stashes\StashInputRecord;
use App\Stashes\StashItemRecord;
use App\Vault\AssetRecord;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\MediaItemRecord;
use Tempest\Database\PrimaryKey;
use Tempest\Http\Status;

test('an external Input Component uses the normal Stash discovery and Vault lifecycle', function (): void {
    $headers = $this->authHeaders();
    $stash = $this->http->post('/api/v1/stashes', [
        'name' => 'External Component Input',
        'download_policy' => 'manual_download',
    ], headers: $headers)->assertStatus(Status::CREATED);
    $stashId = $stash->body['stash']['id'];

    $preflight = $this->http->post('/api/v1/commands', [
        'type' => 'stash.preflight',
        'options' => [
            'source_uri' => 'https://www.youtube.com/channel/UCStashdDemoCh0012345678',
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $preflightCommand = $this->http->get('/api/v1/commands/' . $preflight->body['command_id'], headers: $headers)
        ->assertOk();
    expect($preflightCommand->body['command']['state'])->toBe('completed')
        ->and($preflightCommand->body['command']['result']['discovery']['strategy_key'])->toBe('plugin.complete');

    $add = $this->http->post('/api/v1/stashes/' . $stashId . '/inputs', [
        'preflight_command_id' => $preflight->body['command_id'],
        'options' => ['title_regex_include' => '^Stashd Video 1$'],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $input = StashInputRecord::select()->where('stashId', $stashId)->first();
    expect($input)->not->toBeNull()
        ->and($input->providerKey)->toBe('youtube-component');

    $items = StashItemRecord::select()->where('stashId', $stashId)->all();
    expect($items)->toHaveCount(18)
        ->and(array_values(array_filter($items, static fn (StashItemRecord $item): bool => $item->state->value === 'active')))->toHaveCount(1);

    $this->http->post('/api/v1/stashes/' . $stashId . '/sync', [], headers: $headers)->assertStatus(Status::ACCEPTED);
    $this->processAllJobs();
    $this->http->post('/api/v1/stashes/' . $stashId . '/sync', [], headers: $headers)->assertStatus(Status::ACCEPTED);
    $this->processAllJobs();
    expect(StashItemRecord::select()->where('stashId', $stashId)->all())->toHaveCount(21)
        ->and(MediaItemRecord::count()->execute())->toBe(21);

    $active = array_values(array_filter(
        StashItemRecord::select()->where('stashId', $stashId)->all(),
        static fn (StashItemRecord $item): bool => $item->state->value === 'active',
    ))[0];
    $media = MediaItemRecord::findById(new PrimaryKey((string) $active->mediaItemId));
    expect($media)->not->toBeNull();

    $download = $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => [
            'media_item_id' => (string) $media->id,
            'stash_id' => $stashId,
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $command = $this->http->get('/api/v1/commands/' . $download->body['command_id'], headers: $headers)->assertOk();
    $mediaAfterDownload = MediaItemRecord::findById(new PrimaryKey((string) $media->id));
    expect($command->body['command']['state'])->toBe('completed')
        ->and($mediaAfterDownload?->state->value)->toBe('ready');

    $assets = AssetRecord::select()->where('mediaItemId', (string) $media->id)->all();
    expect($assets)->toHaveCount(3);
    foreach ($assets as $asset) {
        expect($asset->state)->toBe(AssetState::Ready)
            ->and($asset->path)->not->toBeNull()
            ->and(is_file($asset->path))->toBeTrue();
    }
    expect(array_map(static fn (AssetRecord $asset): string => $asset->role->value, $assets))
        ->toEqualCanonicalizing([AssetRole::VaultOriginal->value, AssetRole::MetadataJson->value, AssetRole::SourceThumbnail->value]);
});
