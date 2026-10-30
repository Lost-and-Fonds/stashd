<?php

declare(strict_types=1);

namespace App\EventManagement\Attributes;

use Attribute;

/**
 * Marks a high-frequency observation that should not be treated as durable history by default.
 * It remains available to live progress consumers when a bounded path exists.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TransientObservation
{
    /**
     * Declares that repeated observations may be coalesced or dropped.
     * The marker does not configure a delivery system.
     */
    public function __construct() {}
}
