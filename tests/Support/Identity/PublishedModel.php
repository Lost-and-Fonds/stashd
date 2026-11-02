<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use App\Identity\Identity;
use App\Persistence\Model;

/**
 * Provide a parent override that concrete default names must not inherit.
 */
#[Identity('published-model')]
abstract class PublishedModel extends Model {}
