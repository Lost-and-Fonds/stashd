<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final readonly class PublishRequest
{
    /**
     * @param  list<Setting>  $settings
     * @param  list<Source>  $sources
     * @param  list<Item>  $items
     */
    public function __construct(public string $reference, public array $settings = [], public array $sources = [], public array $items = []) {}
}
