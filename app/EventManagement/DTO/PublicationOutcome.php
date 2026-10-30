<?php

declare(strict_types=1);

namespace App\EventManagement\DTO;

/**
 * Gives one independently publishable Item a bounded, explicit result.
 * Aggregate publication units use their own commit event instead.
 */
enum PublicationOutcome: string
{
    /** The destination acknowledged this Item's publication durably. */
    case PUBLISHED = 'published';

    /** The attempted publication did not succeed. */
    case FAILED = 'failed';

    /** The operation intentionally did not publish this Item. */
    case SKIPPED = 'skipped';
}
