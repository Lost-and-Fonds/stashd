<?php

declare(strict_types=1);

namespace Tests\Support\Persistence;

use App\Identity\Identity;
use App\Persistence\Persistence;
use App\Persistence\Policy;
use Tempest\Database\Table;

/**
 * Read published item identities after renaming their PHP model class.
 */
#[Identity('item')]
#[Table('foundation_records')]
#[Persistence(insert: Policy::Allowed)]
final class RenamedRecord extends Record {}
