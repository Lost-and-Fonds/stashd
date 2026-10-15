<?php

namespace App\RecordsAndArchives;

use Tempest\DateTime\DateTime;

class VaultIdentity
{
    private DateTime $createdAt;

    function __construct(
        public string $format,
        public int $version,
        public string $identifier,
        int $createdAt
    ) {
        $this->createdAt = DateTime::fromTimestamp($createdAt);
    }
}