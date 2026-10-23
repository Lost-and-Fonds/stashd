<?php

namespace App\Integration;

use Tempest\Mapper\Strict;
use Tempest\Validation\Rules;

/**
 * Keep a discovered source locator distinct from canonical Item identity and preserved bytes.
 */
#[Strict]
final class DiscoveredItem
{
    #[Rules\IsNotEmptyString]
    public string $id;
    #[IsProtocolValue('string')]
    public string $reference;
    #[IsProtocolValue('array', orNull: true)]
    public ?array $source;
    #[IsProtocolValue('nonnegative-integer', orNull: true)]
    public ?int $size_bytes;
    #[IsProtocolValue('boolean')]
    public bool $size_estimated;
    /** @var list<\App\Integration\DiscoveryMetadata> */
    #[Rules\IsArrayList]
    public array $metadata;
}
