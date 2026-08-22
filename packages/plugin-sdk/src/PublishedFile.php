<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final readonly class PublishedFile
{
    public function __construct(public string $itemId, public string $sourceReference, public string $relativePath) {}
}
