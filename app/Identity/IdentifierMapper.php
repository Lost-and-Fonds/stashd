<?php

declare(strict_types=1);

namespace App\Identity;

use InvalidArgumentException;
use Tempest\Mapper\Caster;
use Tempest\Mapper\Serializer;

/**
 * Keep typed identities as URNs when Tempest retains or reconstructs input.
 */
final readonly class IdentifierMapper implements Caster, Serializer
{
    /**
     * Reconstruct a retained URN or reuse an already typed identifier.
     */
    public function cast(mixed $input): Identifier
    {
        return match (true) {
            $input instanceof Identifier => $input,
            is_string($input) => Identifier::parse($input),
            default => throw new InvalidArgumentException('Expected a Stashd identifier or URN string.'),
        };
    }

    /**
     * Persist only the public namespace and UUID, without a model registry.
     */
    public function serialize(mixed $input): string
    {
        if (! $input instanceof Identifier) {
            throw new InvalidArgumentException('Expected a Stashd identifier.');
        }

        return (string) $input;
    }
}
