<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Persistence\Model;
use App\Persistence\Persistence;
use App\Persistence\Policy;
use Tempest\Database\Table;

/**
 * The canonical Item identity; preserved revisions remain in the Vault.
 */
#[Table('catalogue_items')]
#[Persistence(insert: Policy::Allowed)]
final class Item extends Model
{
    /**
     * Retain the persisted relationship fields without creating or replacing referenced records.
     */
    public function __construct(
        public string $uri,
    ) {}
}
