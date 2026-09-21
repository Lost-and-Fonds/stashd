<?php

declare(strict_types=1);

use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastItemRepository;
use App\Broadcasts\BroadcastRepository;
use App\Jobs\JobLifecycleSubscriber;
use App\Jobs\JobMessage;
use App\Jobs\JobRecord;
use App\Jobs\JobRepository;
use App\Jobs\JobState;
use App\Stashes\StashItemRepository;
use App\Stashes\StashItemId;
use App\Stashes\StashRepository;
use App\Stashes\StashId;
use App\Vault\ItemId;
use App\Vault\ItemRepository;
use App\Vault\ItemState;
use App\Vault\UpstreamState;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

test('asset completion waits for the stash acquisition batch before rebuilding broadcasts', function (): void {
    $stashes = $this->container->get(StashRepository::class);
    $items = $this->container->get(ItemRepository::class);
    $stashItems = $this->container->get(StashItemRepository::class);
    $broadcasts = $this->container->get(BroadcastRepository::class);
    $broadcastItems = $this->container->get(BroadcastItemRepository::class);
    $jobs = $this->container->get(JobRepository::class);

    $stash = $stashes->create('Acquisition completion proof');
    $item = $items->create(
        providerKey: 'test',
        providerItemId: 'item-1',
        canonicalUri: 'https://example.test/item-1',
        title: 'Item 1',
        state: ItemState::Ready,
        upstreamState: UpstreamState::Available,
    );
    $stashItem = $stashItems->create(StashId::fromPrimaryKey($stash->id), ItemId::fromPrimaryKey($item->id));
    $broadcast = $broadcasts->create(
        stashId: StashId::fromPrimaryKey($stash->id),
        type: 'test',
        name: 'Test broadcast',
        slug: 'test-broadcast',
    );
    $broadcastItems->create(
        broadcastId: BroadcastId::fromPrimaryKey($broadcast->id),
        stashItemId: StashItemId::fromPrimaryKey($stashItem->id),
        itemId: ItemId::fromPrimaryKey($item->id),
    );

    $first = $jobs->createType(
        'core.acquire_assets',
        entityType: 'item',
        entityId: (string) $item->id,
        stashId: (string) $stash->id,
        payload: ['item_id' => (string) $item->id],
    );
    $second = $jobs->createType(
        'core.acquire_assets',
        entityType: 'item',
        entityId: (string) $item->id,
        stashId: (string) $stash->id,
        payload: ['item_id' => (string) $item->id],
    );
    $subscriber = $this->container->get(JobLifecycleSubscriber::class);

    $subscriber->handled(new WorkerMessageHandledEvent(new Envelope(new JobMessage((string) $second->id, 'core.acquire_assets')), 'background'));

    expect(JobRecord::select()->where('intent', 'core.broadcast')->first())->toBeNull();

    $first->state = JobState::Ready;
    $jobs->save($first);
    $subscriber->handled(new WorkerMessageHandledEvent(new Envelope(new JobMessage((string) $first->id, 'core.acquire_assets')), 'background'));

    $rebuilds = JobRecord::select()
        ->where('intent', 'core.broadcast')
        ->where('entityId', (string) $broadcast->id)
        ->all();

    expect($rebuilds)->toHaveCount(1);
});
