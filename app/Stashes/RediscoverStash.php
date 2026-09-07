<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Jobs\JobType;
use App\Providers\ProviderDates;
use App\Support\DurationSeconds;
use App\Vault\ItemRepository;
use InvalidArgumentException;

final readonly class RediscoverStash
{
    public function __construct(
        private StashRepository $stashes,
        private StashInputRepository $inputs,
        private DiscoverStashInput $discovery,
        private ItemRepository $items,
    ) {}

    /** @return array{inputs: int, discovered: int, matched: int, updated: int, fields: int} */
    public function execute(string $stashId): array
    {
        if (! StashId::isValid($stashId) || $this->stashes->find(StashId::parse($stashId)) === null) {
            throw new InvalidArgumentException('Stash not found.');
        }

        $result = ['inputs' => 0, 'discovered' => 0, 'matched' => 0, 'updated' => 0, 'fields' => 0];

        foreach ($this->inputs->listForStash(StashId::parse($stashId)) as $input) {
            $result['inputs']++;
            $providerOptions = $input->options->provider ?? [];
            $providerOptions['skip_size_enrichment'] = true;
            $discovered = $this->discovery->execute([
                'source_uri' => $input->sourceUri,
                'source_title' => $input->title,
                'provider_options' => $providerOptions,
                'backfill_missing' => true,
            ], JobType::core('core.initial_backfill'));

            foreach ($discovered->discoveredItems as $discoveredItem) {
                $result['discovered']++;
                $providerItemId = $discoveredItem['provider_item_id'] ?? null;

                if (! is_string($providerItemId) || $providerItemId === '') {
                    continue;
                }

                $item = $this->items->findByProviderIdentity($input->providerKey, $providerItemId);

                if ($item === null) {
                    continue;
                }

                $result['matched']++;
                $fields = 0;

                if ($item->description === null && is_string($discoveredItem['description'] ?? null)) {
                    $item->description = $discoveredItem['description'];
                    $fields++;
                }

                if ($item->durationSeconds === null && is_int($discoveredItem['duration_seconds'] ?? null)) {
                    $item->durationSeconds = DurationSeconds::toDuration($discoveredItem['duration_seconds']);
                    $fields++;
                }

                if ($item->publishedAt === null && is_string($discoveredItem['published_at'] ?? null)) {
                    $publishedAt = ProviderDates::tryParse($discoveredItem['published_at']);

                    if ($publishedAt !== null) {
                        $item->publishedAt = $publishedAt;
                        $fields++;
                    }
                }

                if ($item->thumbnailUri === null && is_string($discoveredItem['thumbnail_uri'] ?? null)) {
                    $item->thumbnailUri = $discoveredItem['thumbnail_uri'];
                    $fields++;
                }

                if ($item->contentType === null && is_string($discoveredItem['content_type'] ?? null)) {
                    $item->contentType = $discoveredItem['content_type'];
                    $fields++;
                }

                if ($fields > 0) {
                    $this->items->save($item);
                    $result['updated']++;
                    $result['fields'] += $fields;
                }
            }
        }

        return $result;
    }
}
