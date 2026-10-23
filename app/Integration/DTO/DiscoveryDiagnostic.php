<?php

namespace App\Integration\DTO;

use App\Integration\IsProtocolValue;
use Tempest\Mapper\Strict;
use Tempest\Validation\Rules;

/**
 * Explain a scan problem and carry supporting metadata supplied by the integration.
 */
#[Strict]
final class DiscoveryDiagnostic
{
    #[IsProtocolValue('string')]
    public string $message;
    /** @var list<\App\Integration\DTO\DiscoveryMetadata> */
    #[Rules\IsArrayList]
    public array $evidence;
}
