<?php

declare(strict_types=1);

namespace App\Operations;

use InvalidArgumentException;
use Tempest\Validation\Rules\HasLength;
use Tempest\Validation\Rules\MatchesRegEx;

/**
 * Identify a canonical operation independently of any admitted execution.
 *
 * Callers supply stable, case-sensitive identifiers. Observation is input, not
 * identity; separate modes on the same scope represent separate Work.
 */
final readonly class Work
{
    /**
     * Validate bounded identifiers before they become database keys.
     */
    public function __construct(
        public string $type,
        public string $scope,
        public string $mode,
        public string $observation,
    ) {
        $identity = new MatchesRegEx('/\A[a-zA-Z0-9][a-zA-Z0-9:._\/-]{0,199}\z/');
        foreach ([$type, $scope, $mode] as $value) {
            if (! $identity->isValid($value)) {
                throw new InvalidArgumentException('Work identifiers require 1 to 200 ASCII letters, digits or :._/- characters.');
            }
        }

        if (! new HasLength(min: 1, max: 1024)->isValid($observation) || str_contains($observation, "\0")) {
            throw new InvalidArgumentException('Work observation requires 1 to 1024 characters without null bytes.');
        }
    }
}
