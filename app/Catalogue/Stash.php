<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Persistence\Model;
use App\Persistence\Persistence;
use App\Persistence\Policy;
use Tempest\Database\Table;

/**
 * A collection whose membership is independent of source availability.
 */
#[Table('catalogue_stashes')]
#[Persistence(insert: Policy::Allowed)]
final class Stash extends Model
{
    /**
     * Retain the persisted relationship fields without creating or replacing referenced records.
     */
    public function __construct(
        public string $name,
    ) {}
}
