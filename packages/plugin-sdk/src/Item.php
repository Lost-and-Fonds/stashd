<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final readonly class Item
{
    /** @param list<ItemResource> $resources */
    public function __construct(
        public string $id,
        public string $title,
        public array $resources = [],
        public ?string $sourceReference = null,
        public ?string $description = null,
        public ?string $publishedAt = null,
        public ?int $durationSeconds = null,
    ) {}
}
