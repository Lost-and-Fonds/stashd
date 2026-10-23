<?php

namespace App\Integration\DTO;

use App\Integration\IsProtocolValue;
use Tempest\Mapper\Strict;
use Tempest\Validation\Rules;

/**
 * Group the files found in one scan step with the position to save after that step.
 *
 * Core validates the whole batch before saving the files and progress together.
 */
#[Strict]
final class DiscoveryBatch
{
    /** @var list<\App\Integration\DTO\DiscoveredItem> */
    #[Rules\IsArrayList]
    public array $items;
    #[IsProtocolValue('array')]
    public array $progress;
}
