<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Persistence\Model;
use App\Persistence\Persistence;
use App\Persistence\Policy;
use Tempest\Database\Table;

/**
 * Membership in a Stash, without duplicating archival history.
 */
#[Table('catalogue_stash_items')]
#[Persistence(insert: Policy::Allowed)]
final class StashItem extends Model
{
    /**
     * Retain the persisted relationship fields without creating or replacing referenced records.
     */
    public function __construct(
        public string $stash_id,
        public string $item_id,
    ) {}
}
