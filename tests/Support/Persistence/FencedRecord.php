<?php

declare(strict_types=1);

namespace Tests\Support\Persistence;

use App\Persistence\Persistence;
use App\Persistence\Policy;
use Tempest\Database\Table;

/**
 * Exercise fenced persistence through the shared model's public methods.
 */
#[Table('foundation_records')]
#[Persistence(insert: Policy::Fenced, update: Policy::Fenced, delete: Policy::Fenced)]
final class FencedRecord extends Record {}
