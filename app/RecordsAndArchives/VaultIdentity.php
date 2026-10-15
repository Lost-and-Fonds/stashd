<?php

namespace App\RecordsAndArchives;

use Tempest\DateTime\DateTime;

class VaultIdentity
{
    public DateTime $createdAt;

    function __construct(
        public string $format,
        public int $version,
        public string $identifier,
        string $createdAt
    ) {
        $this->createdAt = DateTime::parse($createdAt);
    }
}