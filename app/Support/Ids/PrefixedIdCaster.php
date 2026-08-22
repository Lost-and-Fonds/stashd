<?php

declare(strict_types=1);

namespace App\Support\Ids;

use Tempest\Mapper\Caster;
use Tempest\Mapper\ConfigurableCaster;
use Tempest\Mapper\Context;
use Tempest\Mapper\DynamicCaster;
use Tempest\Reflection\PropertyReflector;
use Tempest\Reflection\TypeReflector;
use Tempest\Support\Priority;

/**
 * Auto-discovered: casts any PrefixedId-typed property (UserId, StashId, ...) from its raw string column.
 *
 * Priority::HIGHEST because Tempest's built-in ObjectCaster (Priority::HIGH) accepts *any*
 * class-typed property and would otherwise win the race and hydrate PrefixedId subclasses via
 * generic array-to-object mapping instead of parsing the raw string column.
 */
#[Priority(Priority::HIGHEST)]
final readonly class PrefixedIdCaster implements Caster, ConfigurableCaster, DynamicCaster
{
    /** @param class-string<PrefixedId> $idClass */
    public function __construct(
        private string $idClass,
    ) {}

    public static function accepts(PropertyReflector|TypeReflector $input): bool
    {
        $type = $input instanceof PropertyReflector ? $input->getType() : $input;

        return $type->isClass() && is_a($type->getName(), PrefixedId::class, allow_string: true);
    }

    public static function configure(PropertyReflector $property, Context $context): self
    {
        $idClass = $property->getType()->getName();

        if (! is_a($idClass, PrefixedId::class, allow_string: true)) {
            throw new \LogicException("Expected a PrefixedId subclass, got: {$idClass}");
        }

        return new self($idClass);
    }

    public function cast(mixed $input): PrefixedId
    {
        return ($this->idClass)::parse(is_string($input) ? $input : '');
    }
}
