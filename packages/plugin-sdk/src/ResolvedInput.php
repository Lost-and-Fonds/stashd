<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final readonly class ResolvedInput
{
    public function __construct(
        public string $id,
        public ?string $canonicalReference = null,
        public ?string $kind = null,
        public ?string $title = null,
        public ?string $artworkReference = null,
        public ?int $estimatedItemCount = null,
    ) {}
}
