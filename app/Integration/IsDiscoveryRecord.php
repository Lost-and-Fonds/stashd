<?php

namespace App\Integration;

use Tempest\Validation\Rule;

/**
 * Require literal field names in adapter output instead of Tempest's dotted form names.
 */
final readonly class IsDiscoveryRecord implements Rule
{
    /**
     * Reject dotted names before mapping can turn them into nested fields and overwrite checked values.
     */
    public function isValid(mixed $value): bool
    {
        return is_array($value) && ! array_any(array_keys($value), static fn (int|string $key): bool => is_string($key) && str_contains($key, '.'));
    }
}
