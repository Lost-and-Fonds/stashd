<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final readonly class DerivedArtifact
{
    public function __construct(
        public string $itemId,
        public string $reference,
        public string $derivedFromReference,
        public string $derivationKey,
        public string $kind,
        public ?string $mediaType = null,
        public int $sizeBytes = 0,
    ) {}
}
