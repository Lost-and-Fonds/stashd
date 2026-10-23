<?php

namespace App\Integration;

use Tempest\Mapper\Strict;
use Tempest\Validation\Rules;

/**
 * Carry coverage diagnostics with opaque component-owned evidence facets.
 */
#[Strict]
final class DiscoveryDiagnostic
{
    #[IsProtocolValue('string')]
    public string $message;
    /** @var list<\App\Integration\DiscoveryMetadata> */
    #[Rules\IsArrayList]
    public array $evidence;
}
