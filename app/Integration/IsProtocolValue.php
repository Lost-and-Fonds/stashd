<?php

namespace App\Integration;

use Attribute;
use Tempest\Validation\Rule;

/**
 * Reject wire-value coercion that Tempest's general-purpose scalar rules permit.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class IsProtocolValue implements Rule
{
    /**
     * Select a strict protocol value and whether explicit null is valid.
     *
     * @param 'string'|'boolean'|'nonnegative-integer'|'array' $type
     */
    public function __construct(
        private string $type,
        private bool $orNull = false,
    ) {}

    /**
     * Keep protocol types distinct while accepting Tempest's internal array view during class validation.
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
