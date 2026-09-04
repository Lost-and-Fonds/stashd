<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Jobs\JobType;
use App\Providers\ProviderDates;
use App\Support\DurationSeconds;
use App\Vault\MediaItemRepository;
use InvalidArgumentException;

final readonly class RediscoverStash
{
    public function __construct(
        private StashRepository $stashes,
        private StashInputRepository $inputs,
        private DiscoverStashInput $discovery,
        private MediaItemRepository $mediaItems,
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
            $discovered = $this->discovery->execute([
                'source_uri' => $input->sourceUri,
                'source_title' => $input->title,
            ], JobType::core('core.initial_backfill'));

            foreach ($discovered->discoveredItems as $item) {
                $result['discovered']++;
                $providerItemId = $item['provider_item_id'] ?? null;

                if (! is_string($providerItemId) || $providerItemId === '') {
                    continue;
                }

                $mediaItem = $this->mediaItems->findByProviderIdentity($input->providerKey, $providerItemId);

                if ($mediaItem === null) {
                    continue;
                }

                $result['matched']++;
                $fields = 0;

                if ($mediaItem->description === null && is_string($item['description'] ?? null)) {
                    $mediaItem->description = $item['description'];
                    $fields++;
                }

                if ($mediaItem->durationSeconds === null && is_int($item['duration_seconds'] ?? null)) {
                    $mediaItem->durationSeconds = DurationSeconds::toDuration($item['duration_seconds']);
                    $fields++;
                }

                if ($mediaItem->publishedAt === null && is_string($item['published_at'] ?? null)) {
                    $publishedAt = ProviderDates::tryParse($item['published_at']);

                    if ($publishedAt !== null) {
                        $mediaItem->publishedAt = $publishedAt;
                        $fields++;
                    }
                }

                if ($mediaItem->thumbnailUri === null && is_string($item['thumbnail_uri'] ?? null)) {
                    $mediaItem->thumbnailUri = $item['thumbnail_uri'];
                    $fields++;
                }

                if ($mediaItem->contentType === null && is_string($item['content_type'] ?? null)) {
                    $mediaItem->contentType = $item['content_type'];
                    $fields++;
                }

                if ($fields > 0) {
                    $this->mediaItems->save($mediaItem);
                    $result['updated']++;
                    $result['fields'] += $fields;
                }
            }
        }

        return $result;
    }
}
