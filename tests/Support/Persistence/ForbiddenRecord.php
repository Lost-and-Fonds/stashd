<?php

declare(strict_types=1);

namespace Tests\Support\Persistence;

use Tempest\Database\Table;

/**
 * Exercise forbidden persistence through the shared model's public methods.
 */
#[Table('foundation_records')]
final class ForbiddenRecord extends Record {}
