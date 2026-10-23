<?php

namespace App\Integration;

use Tempest\Mapper\Strict;

/**
 * Retain a component-owned facet envelope without interpreting its opaque JSON payload.
 */
#[Strict]
final class DiscoveryMetadata
{
    #[IsProtocolValue('string')]
    public string $schema;
    #[IsProtocolValue('string')]
    public string $json;
}
