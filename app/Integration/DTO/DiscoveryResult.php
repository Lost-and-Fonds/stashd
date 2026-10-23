<?php

namespace App\Integration\DTO;

use App\Integration\IsProtocolValue;
use Tempest\Mapper\Strict;
use Tempest\Validation\Rules;

/**
 * Report whether a finished scan found everything, skipped known entries, or cannot say.
 *
 * The batch validator checks that each result includes only its required details.
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
