<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Persistence\Model;
use App\Persistence\Persistence;
use App\Persistence\Policy;
use Tempest\Database\Table;

/**
 * The existing acquisition identity map, also used by Catalogue.
 */
#[Table('acquisition_sources')]
#[Persistence(insert: Policy::Allowed)]
final class ItemSource extends Model
{
    /**
     * Retain the persisted relationship fields without creating or replacing referenced records.
     */
    public function __construct(
        public string $input_id,
        public string $source_id,
        public string $item_uri,
    ) {}
}
