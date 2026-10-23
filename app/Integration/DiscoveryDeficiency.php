<?php

namespace App\Integration;

use Tempest\Mapper\Strict;
use Tempest\Validation\Rules;

/**
 * Classify one known coverage gap without pretending that enumeration was exhaustive.
 */
#[Strict]
final class DiscoveryDeficiency
{
    #[Rules\IsIn(['retryable', 'terminal', 'unknown'])]
    public string $disposition;
    #[IsProtocolValue('array')]
    public array $diagnostic;
}
