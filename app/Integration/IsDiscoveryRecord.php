<?php

namespace App\Integration;

use Tempest\Validation\Rule;

/**
 * Keep protocol record keys literal rather than accepting Tempest form-field aliases.
 */
final readonly class IsDiscoveryRecord implements Rule
{
    /**
     * Reject dotted keys before the mapper's undot expansion can rewrite validated fields.
     */
    public function isValid(mixed $value): bool
    {
        return is_array($value) && ! array_any(array_keys($value), static fn (int|string $key): bool => is_string($key) && str_contains($key, '.'));
    }
}
