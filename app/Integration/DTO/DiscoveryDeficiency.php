<?php

namespace App\Integration\DTO;

use App\Integration\IsProtocolValue;
use Tempest\Mapper\Strict;
use Tempest\Validation\Rules;

/**
 * Describe why part of a scan is incomplete and whether trying again may help.
 */
#[Strict]
final class DiscoveryDeficiency
{
    #[Rules\IsIn(['retryable', 'terminal', 'unknown'])]
    public string $disposition;
    #[IsProtocolValue('array')]
    public array $diagnostic;
}
