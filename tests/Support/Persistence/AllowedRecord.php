<?php

declare(strict_types=1);

namespace Tests\Support\Persistence;

use App\Persistence\Persistence;
use App\Persistence\Policy;
use Tempest\Database\Table;

/**
 * Exercise allowed persistence through the shared model's public methods.
 */
#[Table('foundation_records')]
#[Persistence(insert: Policy::Allowed, update: Policy::Allowed, delete: Policy::Allowed)]
final class AllowedRecord extends Record {}
