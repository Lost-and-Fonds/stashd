<?php

declare(strict_types=1);

namespace Tests\Fixtures\Mapping;

use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\Mapper\MapFrom;
use Tempest\Mapper\MapTo;

/**
 * Spike fixture: camelCase PHP properties against snake_case SQLite columns.
 *
 * MapFrom/MapTo attributes are intentionally absent on the baseline model.
 * See TempestMappingTestRecordWithMapAttributes for the annotated variant.
 */
#[Table(name: 'tempest_mapping_test')]
final class TempestMappingTestRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    public function __construct(
        public string $createdAt,
        public bool $supportsHardlinks,
        public ?float $progressPercent = null,
        public ?string $lastCheckedAt = null,
    ) {}
}

/**
 * Same table shape, but each property declares explicit DB column aliases.
 *
 * MapFrom/MapTo only apply to Tempest's generic array/object mapper — not to
 * database SELECT/INSERT/UPDATE SQL generation.
 */
#[Table(name: 'tempest_mapping_test')]
final class TempestMappingTestRecordWithMapAttributes
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    public function __construct(
        #[MapFrom('created_at'), MapTo('created_at')]
        public string $createdAt,
        #[MapFrom('supports_hardlinks'), MapTo('supports_hardlinks')]
        public bool $supportsHardlinks,
        #[MapFrom('progress_percent'), MapTo('progress_percent')]
        public ?float $progressPercent = null,
        #[MapFrom('last_checked_at'), MapTo('last_checked_at')]
        public ?string $lastCheckedAt = null,
    ) {}
}

/**
 * Tempest-native pattern: snake_case properties matching snake_case columns.
 */
#[Table(name: 'tempest_mapping_test')]
final class TempestMappingSnakeCaseRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    public function __construct(
        public string $created_at,
        public bool $supports_hardlinks,
        public ?float $progress_percent = null,
        public ?string $last_checked_at = null,
    ) {}
}

/**
 * Control fixture: camelCase properties against camelCase columns (Stashd foundation).
 */
#[Table(name: 'tempest_mapping_camel')]
final class TempestMappingCamelCaseRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    public function __construct(
        public string $createdAt,
        public bool $supportsHardlinks,
        public ?float $progressPercent = null,
        public ?string $lastCheckedAt = null,
    ) {}
}
