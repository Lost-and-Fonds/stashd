<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Capabilities;

final readonly class PublishedOutput
{
    public function __construct(public string $reference, public string $relativePath, public int $sizeBytes, public ?string $mediaType) {}
}
