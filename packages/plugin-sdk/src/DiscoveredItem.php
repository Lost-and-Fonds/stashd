<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final readonly class DiscoveredItem
{
    public function __construct(
        public string $id,
        public string $reference,
        public string $title,
        public ?string $description = null,
        public ?string $publishedAt = null,
        public ?string $artworkReference = null,
        public ?int $durationSeconds = null,
        public ?string $kind = null,
    ) {}
}
