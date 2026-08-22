<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final readonly class ItemResource
{
    public function __construct(
        public string $reference,
        public string $kind,
        public ?string $derivationKey = null,
        public ?string $url = null,
        public ?string $mediaType = null,
        public int $sizeBytes = 0,
    ) {}
}
