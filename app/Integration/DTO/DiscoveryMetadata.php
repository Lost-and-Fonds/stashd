<?php

namespace App\Integration\DTO;

use App\Integration\IsProtocolValue;
use Tempest\Mapper\Strict;

/**
 * Pair an integration-specific schema name with its JSON metadata.
 *
 * Core checks the field types but leaves the JSON contents to the integration.
 */
#[Strict]
final class DiscoveryMetadata
{
    #[IsProtocolValue('string')]
    public string $schema;
    #[IsProtocolValue('string')]
    public string $json;
}
