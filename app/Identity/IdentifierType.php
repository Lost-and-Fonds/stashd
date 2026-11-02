<?php

declare(strict_types=1);

namespace App\Identity;

use InvalidArgumentException;

/**
 * Keep archive and Operations identities in distinct Stashd URN namespaces.
 */
enum IdentifierType: string
{
    case Item = 'item';
    case Asset = 'asset';
    case Vault = 'vault';
    case Assignment = 'assignment';
    case Attempt = 'attempt';
    case Demand = 'demand';
    case Checkpoint = 'checkpoint';

    /**
     * Resolve the model's declared identity domain without a second central mapping.
     *
     * @param class-string $model
     */
    public static function forModel(string $model): self
    {
        $constant = $model . '::IDENTIFIER_TYPE';
        $declared = defined($constant) ? constant($constant) : null;

        return $declared instanceof self ? $declared : throw new InvalidArgumentException('No identifier type for model ' . $model);
    }
}
