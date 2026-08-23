<?php

declare(strict_types=1);

namespace Tests\Fixtures\Mapping;

use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\DateTime\DateTime;

/** PostgreSQL fixture for camelCase PHP properties and columns. */
#[Table(name: 'tempest_mapping_camel')]
final class TempestMappingCamelCaseDateTimeRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    public function __construct(
        public DateTime $createdAt,
        public bool $supportsHardlinks,
        public ?float $progressPercent = null,
        public ?DateTime $lastCheckedAt = null,
    ) {}
}
