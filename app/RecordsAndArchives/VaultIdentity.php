<?php

namespace App\RecordsAndArchives;

use League\Uri\Urn;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\FormatPattern;
use Tempest\Validation\Rules\HasDateTimeFormat;

class VaultIdentity
{
    #[HasDateTimeFormat(FormatPattern::ISO8601)]
    public DateTime $createdAt;

    function __construct(
        public string $format,
        public int $version,
        public Urn $identifier,
        string $createdAt
    ) {
        $this->createdAt = DateTime::parse($createdAt);
    }
}