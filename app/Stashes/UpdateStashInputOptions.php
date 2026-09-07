<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Downloads\DownloadPolicyEvaluator;
use App\Jobs\JobDispatcher;
use App\Providers\InputOption;
use App\Providers\ProviderRegistry;
use App\Providers\ResolvedInput;
use App\Providers\StashdUri;
use App\System\State\StateTransitionService;
use App\Vault\ItemRepository;

final readonly class UpdateStashInputOptions
{
    public function __construct(
        private StashInputRepository $inputs,
        private StashItemRepository $stashItems,
        private ItemRepository $items,
        private ProviderRegistry $providers,
        private StashInputFilter $filter,
        private StateTransitionService $transitions,
        private JobDispatcher $jobDispatcher,
        private DownloadPolicyEvaluator $downloadPolicy,
    ) {}

    public function execute(StashRecord $stash, StashInputRecord $input, ?StashInputOptions $options): StashInputRecord
    {
        $input = $this->inputs->updateOptions($input, $options);
        $declaredOptions = $this->declaredOptions($input);
        $downloadableItemIds = [];

        foreach ($this->stashItems->listForStash(
            StashId::fromPrimaryKey($stash->id),
            stashInputId: StashInputId::fromPrimaryKey($input->id),
        ) as $stashItem) {
            $item = $this->items->find($stashItem->itemId);

            if ($item === null) {
                continue;
            }

            $reason = $this->filter->ignoredReason(
                $item->title,
                $item->contentType,
                $options,
                $declaredOptions,
            );

            if ($reason === null && $stashItem->state === StashItemState::Ignored && $this->filter->isFilterReason($stashItem->ignoredReason)) {
                $stashItem->ignoredReason = null;
                $this->transitions->transitionStashItem($stashItem, StashItemState::Active);
                $downloadableItemIds[] = (string) $item->id;
            }

            if ($reason !== null && $stashItem->state === StashItemState::Active) {
                $stashItem->ignoredReason = $reason;
                $this->transitions->transitionStashItem($stashItem, StashItemState::Ignored);
            }

            if ($reason !== null && $stashItem->state === StashItemState::Ignored
                && $this->filter->isFilterReason($stashItem->ignoredReason)
                && $stashItem->ignoredReason !== $reason) {
                $stashItem->ignoredReason = $reason;
                $stashItem->save();
            }
        }

        if ($this->downloadPolicy->allowsAutomaticDownload($stash->downloadPolicy)) {
            foreach ($downloadableItemIds as $itemId) {
                $this->jobDispatcher->dispatch('core.download', 'item', $itemId, (string) $stash->id, [
                    'item_id' => $itemId,
                    'stash_id' => (string) $stash->id,
                ], 'background');
            }
        }

        return $input;
    }

    /** @return list<InputOption> */
    public function declaredOptions(StashInputRecord $input): array
    {
        return $this->providers->get($input->providerKey)->inputOptions(new ResolvedInput(
            providerKey: $input->providerKey,
            inputType: $input->inputType->value,
            sourceUri: StashdUri::parse($input->sourceUri),
            providerInputId: $input->providerInputId,
            title: $input->title,
        ));
    }
}
