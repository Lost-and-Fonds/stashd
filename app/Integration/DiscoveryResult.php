<?php

namespace App\Integration;

use Tempest\Mapper\Strict;
use Tempest\Validation\Rules;

/**
 * Describe terminal coverage; the boundary enforces which fields belong to each variant.
 */
#[Strict]
final class DiscoveryResult
{
    #[Rules\IsIn(['exhaustive', 'partial', 'indeterminate'])]
    public string $kind;
    #[IsProtocolValue('string', orNull: true)]
    public ?string $refresh_state = null;
    #[IsProtocolValue('array', orNull: true)]
    public ?array $deficiencies = null;
    #[IsProtocolValue('array', orNull: true)]
    public ?array $diagnostic = null;
}
