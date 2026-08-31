<?php

declare(strict_types=1);

namespace App\Providers;

use App\Providers\Core\DiscoveredItem;

interface MetadataStrategyHandler
{
    public function strategyKey(): string;

    /** @return DiscoveredItem */
    public function enrich(ResolvedInput $input, DiscoveredItem $item): DiscoveredItem;
}
