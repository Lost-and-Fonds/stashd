<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final readonly class Source
{
    /** @param list<Setting> $settings */
    public function __construct(public string $reference, public array $settings = []) {}
}
