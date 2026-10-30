<?php

declare(strict_types=1);

namespace App\EventManagement\Attributes;

use Attribute;

/**
 * Marks an ordinary Core event as relevant to preservation metadata.
 * It does not create PREMIS records or exports by itself.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class PremisSignificant
{
    /**
     * Declares that a future archival recorder should consider this event.
     * No PREMIS integration is active in this department foundation.
     */
    public function __construct() {}
}
