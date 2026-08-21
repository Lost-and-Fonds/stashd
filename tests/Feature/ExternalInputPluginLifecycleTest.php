<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Providers\StashdUri;
use App\Stashes\StashId;
use App\Stashes\StashInputOptions;
use App\Stashes\StashInputRecord;
use App\Stashes\StashInputRepository;
use App\Stashes\StashInputType;
use App\Stashes\StashItemRecord;
use App\Stashes\StashItemState;
use App\Stashes\SyncMode;
use App\Vault\AssetRecord;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\MediaItemRecord;
use App\Vault\MediaItemRepository;
use App\Vault\MediaItemSourceRepository;
use App\Vault\MediaItemState;
use Tempest\Database\PrimaryKey;
use Tempest\Http\Status;

test('an external Input Component uses the normal Stash discovery and Vault lifecycle', function (): void {
    requireExternalInputPluginRuntime($this);
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
        'options' => [
            'title_regex_include' => '^Stashd Video 1$',
            'provider' => ['include_shorts' => true, 'include_live' => true],
        ],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();

    $input = StashInputRecord::select()->where('stashId', $stashId)->first();
    expect($input)->not->toBeNull()
        ->and($input->providerKey)->toBe('youtube')
        ->and($input->options?->provider)->toBe(['include_shorts' => true, 'include_live' => true]);
    $inputApi = $this->http->get('/api/v1/stashes/' . $stashId . '/inputs', headers: $headers)->assertOk()->body['inputs'][0];
    expect(array_column($inputApi['input_options'], 'key'))->toContain('include_shorts', 'include_live', 'include_captions', 'caption_languages');

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

test('an existing logical YouTube Input routes to the Component without duplicates', function (): void {
    requireExternalInputPluginRuntime($this);
    $headers = $this->authHeaders();
    $stash = $this->http->post('/api/v1/stashes', [
        'name' => 'Existing YouTube Input',
        'download_policy' => 'manual_download',
    ], headers: $headers)->assertStatus(Status::CREATED);
    $stashId = StashId::parse($stash->body['stash']['id']);
    $inputs = $this->container->get(StashInputRepository::class);
    $mediaItems = $this->container->get(MediaItemRepository::class);
    $sources = $this->container->get(MediaItemSourceRepository::class);

    $input = $inputs->create(
        stashId: $stashId,
        providerKey: 'youtube',
        inputType: StashInputType::Channel,
        sourceUri: 'https://www.youtube.com/channel/UCStashdDemoCh0012345678',
        providerInputId: 'UCStashdDemoCh0012345678',
        title: 'Existing YouTube Input',
        syncMode: SyncMode::Automatic,
        options: new StashInputOptions(provider: ['include_shorts' => true, 'include_live' => true]),
    );
    $media = $mediaItems->create(
        providerKey: 'youtube',
        providerItemId: 'StashdVid01',
        canonicalUri: StashdUri::parse('https://www.youtube.com/watch?v=StashdVid01'),
        title: 'Stashd Video 1',
        state: MediaItemState::Discovered,
        contentType: 'regular',
    );
    $inputId = \App\Stashes\StashInputId::fromPrimaryKey($input->id);
    $sources->create(
        mediaItemId: \App\Vault\MediaItemId::fromPrimaryKey($media->id),
        providerKey: 'youtube',
        providerInputId: $input->providerInputId,
        discoveredUri: $media->canonicalUri,
        stashInputId: $inputId,
        position: 1,
    );
    $this->container->get(\App\Stashes\StashItemRepository::class)->create(
        stashId: $stashId,
        mediaItemId: \App\Vault\MediaItemId::fromPrimaryKey($media->id),
        stashInputId: $inputId,
        state: StashItemState::Active,
        position: 1,
    );

    $this->http->post('/api/v1/stashes/' . $stashId->toString() . '/sync', [], headers: $headers)->assertStatus(Status::ACCEPTED);
    $this->processAllJobs();

    expect(MediaItemRecord::count()->execute())->toBe(4)
        ->and(StashItemRecord::select()->where('stashId', $stashId->toString())->all())->toHaveCount(4)
        ->and(StashInputRecord::select()->where('providerKey', 'youtube')->all())->toHaveCount(1);

    $download = $this->http->post('/api/v1/commands', [
        'type' => 'item.download',
        'options' => ['media_item_id' => (string) $media->id, 'stash_id' => $stashId->toString()],
    ], headers: $headers)->assertStatus(Status::CREATED);
    $this->processAllJobs();
    $updated = MediaItemRecord::findById(new PrimaryKey((string) $media->id));

    expect($this->http->get('/api/v1/commands/' . $download->body['command_id'], headers: $headers)->body['command']['state'])->toBe('completed')
        ->and($updated?->state)->toBe(MediaItemState::Ready);
});
