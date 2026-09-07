<?php

declare(strict_types=1);

namespace App\Fixity;

use App\Config\StashdConfig;
use Tempest\DateTime\DateTime;

final readonly class VerificationPolicy
{
    public function __construct(private StashdConfig $config) {}

    public function intervalDays(): int
    {
        return $this->config->verificationIntervalDays;
    }

    public function dueAt(DateTime $lastVerifiedAt): DateTime
    {
        return $lastVerifiedAt->plusDays($this->intervalDays());
    }
}
