<?php

namespace App\Integration\DTO;

use App\Integration\IsProtocolValue;
use Tempest\Mapper\Strict;
use Tempest\Validation\Rules;

/**
 * Describe a file found at the source, before it has been preserved in the Vault.
 *
 * The ID identifies a location within an Input; it is not a canonical Item URI.
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
    /** @var list<\App\Integration\DTO\DiscoveryMetadata> */
    #[Rules\IsArrayList]
    public array $metadata;
}
