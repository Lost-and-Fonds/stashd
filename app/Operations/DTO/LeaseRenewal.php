<?php

declare(strict_types=1);

namespace App\Operations\DTO;

use DateTimeImmutable;

/**
 * Report an actual PostgreSQL renewal decision without granting future authority.
 */
final readonly class LeaseRenewal
{
    /**
     * Preserve the database decision time and accepted deadline for diagnostics.
     */
    public function __construct(
        public bool $accepted,
        public DateTimeImmutable $decidedAt,
        public ?DateTimeImmutable $expiresAt,
    ) {}
}
