<?php

declare(strict_types=1);

namespace App\EventManagement\Attributes;

use Attribute;

/**
 * Marks an event as a candidate for durable operational history.
 * The marker does not provide persistence or transaction guarantees.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class DurableHistory
{
    /**
     * Declares explicit interest in preserving this event operationally.
     * Recording remains inactive until a real recorder is integrated.
     */
    public function __construct() {}
}
