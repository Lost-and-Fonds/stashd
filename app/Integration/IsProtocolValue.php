<?php

namespace App\Integration;

use Attribute;
use Tempest\Validation\Rule;

/**
 * Check exact adapter field types where Tempest's general rules allow conversions.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class IsProtocolValue implements Rule
{
    /**
     * Choose the required field type and whether null is allowed.
     *
     * @param 'string'|'boolean'|'nonnegative-integer'|'array' $type
     */
    public function __construct(
        private string $type,
        private bool $orNull = false,
    ) {}

    /**
     * Reject lookalike values such as a numeric string where an integer is required.
     *
     * Also accept Tempest's array views, which it uses while validating nested arrays.
     */
    public function isValid(mixed $value): bool
    {
        if ($value === null) {
            return $this->orNull;
        }

        return match ($this->type) {
            'string' => is_string($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) || $value instanceof \Tempest\Support\Arr\ImmutableArray || $value instanceof \Tempest\Support\Arr\MutableArray,
            'nonnegative-integer' => is_int($value) && $value >= 0,
        };
    }
}
