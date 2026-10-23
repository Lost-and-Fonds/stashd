<?php

namespace App\Integration;

use Tempest\Mapper\Strict;
use Tempest\Validation\Rules;

/**
 * Carry the host-validated bounded observations and their indivisible progress acknowledgment.
 */
#[Strict]
final class DiscoveryBatch
{
    /** @var list<\App\Integration\DiscoveredItem> */
    #[Rules\IsArrayList]
    public array $items;
    #[IsProtocolValue('array')]
    public array $progress;
}
