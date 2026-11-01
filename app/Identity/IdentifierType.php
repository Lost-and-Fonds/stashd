<?php

declare(strict_types=1);

namespace App\Identity;

use App\Operations\Assignment;
use App\Operations\Models\Attempt;
use App\Operations\Models\Demand;
use App\Persistence\Model;
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

    /**
     * Resolve declared model identities without guessing from class or table names.
     * Existing Operations classes keep their mapping until they adopt the shared base.
     *
     * @param class-string $model
     */
    public static function forModel(string $model): self
    {
        $constant = $model . '::IDENTIFIER_TYPE';
        $declared = is_subclass_of($model, Model::class) && defined($constant) ? constant($constant) : null;

        return match ($model) {
            Assignment::class => self::Assignment,
            Attempt::class => self::Attempt,
            Demand::class => self::Demand,
            default => $declared instanceof self ? $declared : throw new InvalidArgumentException('No identifier type for model ' . $model),
        };
    }
}
