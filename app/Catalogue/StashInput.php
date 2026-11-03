<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Persistence\Model;
use App\Persistence\Persistence;
use App\Persistence\Policy;
use Tempest\Database\Table;

/**
 * Attach a Stash to an existing Integration configuration.
 */
#[Table('catalogue_stash_inputs')]
#[Persistence(insert: Policy::Allowed)]
final class StashInput extends Model
{
    /**
     * Retain the persisted relationship fields without creating or replacing referenced records.
     */
    public function __construct(
        public string $stash_id,
        public string $input_id,
    ) {}
}
