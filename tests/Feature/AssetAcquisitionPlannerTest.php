<?php

declare(strict_types=1);

use App\Broadcasts\BroadcastRepository;
use App\Plugins\ExternalBroadcastPluginDefinition;
use App\Plugins\ExternalBroadcastPluginRegistry;
use App\Plugins\ExternalInputPluginRegistry;
use App\Plugins\PluginInputDefinition;
use App\Stashes\AssetAcquisitionPlanner;
use App\Stashes\StashId;
use App\Stashes\StashInputId;
use App\Stashes\StashInputOptions;
use App\Stashes\StashInputRepository;
use App\Stashes\StashInputState;
use App\Stashes\StashInputType;
use App\Stashes\StashItemRepository;
use App\Stashes\StashRepository;
use App\Vault\ItemId;
use App\Vault\ItemRepository;
use App\Vault\ItemState;
use App\Vault\UpstreamState;
use App\Jobs\JobRecord;
use App\Jobs\JobRepository;
use App\Config\StashdConfig;

test('caption broadcast dispatches supplementary acquisition for an enabled input capability', function (): void {
    $inputDefinition = PluginInputDefinition::from([
        'kind' => 'input',
        'id' => 'youtube',
        'provider_key' => 'youtube',
        'name' => 'YouTube',
        'input_options' => [
            ['key' => 'include_captions', 'label' => 'Include captions', 'type' => 'bool', 'default' => false],
            ['key' => 'include_auto_captions', 'label' => 'Include auto captions', 'type' => 'bool', 'default' => false],
            ['key' => 'caption_languages', 'label' => 'Caption languages', 'type' => 'text', 'default' => ''],
        ],
        'asset_capabilities' => [
            ['role' => 'captions', 'kind' => 'subtitle', 'option' => 'include_captions'],
        ],
    ], __DIR__) ?? throw new RuntimeException('Failed to create YouTube definition.');

    $broadcastDefinition = ExternalBroadcastPluginDefinition::fromManifest([
        'id' => 'podcast',
        'broadcast_key' => 'podcast',
        'asset_requirements' => [[
            'role' => 'captions',
            'kind' => 'subtitle',
            'required' => false,
            'when' => ['setting' => 'captions', 'not_equals' => 'off'],
        ]],
    ], __DIR__, '/tmp/plugin.sock');

    expect($broadcastDefinition)->not->toBeNull();

    $this->container->singleton(ExternalInputPluginRegistry::class, new ExternalInputPluginRegistry([], [$inputDefinition]));
    $this->container->singleton(ExternalBroadcastPluginRegistry::class, new ExternalBroadcastPluginRegistry([$broadcastDefinition]));

    $stashes = $this->container->get(StashRepository::class);
    $inputs = $this->container->get(StashInputRepository::class);
    $items = $this->container->get(ItemRepository::class);
    $stashItems = $this->container->get(StashItemRepository::class);
    $broadcasts = $this->container->get(BroadcastRepository::class);

    $stash = $stashes->create('Caption planner proof');
    $stashId = StashId::fromPrimaryKey($stash->id);
    $input = $inputs->create(
        stashId: $stashId,
        providerKey: 'youtube',
        inputType: StashInputType::Video,
        sourceUri: 'https://youtube.test/watch?v=goldenvid01',
        providerInputId: 'goldenvid01',
        state: StashInputState::Ready,
        options: new StashInputOptions(provider: [
            'include_captions' => true,
            'include_auto_captions' => false,
            'caption_languages' => 'en',
        ]),
    );
    $item = $items->create(
        providerKey: 'youtube',
        providerItemId: 'goldenvid01',
        canonicalUri: 'https://youtube.test/watch?v=goldenvid01',
        title: 'Golden Path Video',
        state: ItemState::Ready,
        upstreamState: UpstreamState::Available,
    );
    $stashItems->create(
        stashId: $stashId,
        itemId: ItemId::fromPrimaryKey($item->id),
        stashInputId: StashInputId::fromPrimaryKey($input->id),
    );
    $broadcast = $broadcasts->create(
        stashId: $stashId,
        type: 'podcast',
        name: 'Caption Podcast',
        slug: 'caption-podcast',
        settings: ['captions' => 'creator_only', 'caption_languages' => 'en'],
    );
    mkdir($this->container->get(StashdConfig::class)->vaultPath(), 0775, true);
    $this->container->get(JobRepository::class)->createType(
        'core.download',
        entityType: 'item',
        entityId: (string) $item->id,
        stashId: (string) $stash->id,
        payload: ['item_id' => (string) $item->id, 'stash_id' => (string) $stash->id],
    );

    $dispatched = $this->container->get(AssetAcquisitionPlanner::class)->dispatchMissingForBroadcast($broadcast);

    $job = JobRecord::select()
        ->where('intent', 'core.acquire_assets')
        ->where('entityId', (string) $item->id)
        ->first();

    expect($input->options?->provider)->toBe([
        'include_captions' => true,
        'include_auto_captions' => false,
        'caption_languages' => 'en',
    ])
        ->and($inputDefinition->assetCapabilities[0]->enabled($input->options, $inputDefinition->options))->toBeTrue()
        ->and($broadcastDefinition->assetRequirements[0]->enabled($broadcast->settings ?? []))->toBeTrue()
        ->and($dispatched)->toBe(1)
        ->and($job)->not->toBeNull()
        ->and($job->payload['roles'] ?? null)->toBe(['captions'])
        ->and($job->payload['provider_options'] ?? null)->toBe($input->options->provider);
});
