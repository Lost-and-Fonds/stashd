<?php

declare(strict_types=1);

namespace App\Identity;

use InvalidArgumentException;
use Tempest\Mapper\Caster;
use Tempest\Mapper\Serializer;

final readonly class IdentifierMapper implements Caster, Serializer
{
    public function cast(mixed $input): Identifier
    {
        return $input instanceof Identifier ? $input : Identifier::parse($input);
    }

    public function serialize(mixed $input): string
    {
        if (! $input instanceof Identifier) {
            throw new InvalidArgumentException('Expected a Stashd identifier.');
        }

        return (string) $input;
    }
}
