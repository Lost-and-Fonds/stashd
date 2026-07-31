<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;

/**
 * `raw_metadata_snapshots` was scaffolding for a provenance feature that
 * never shipped -- its only readers were the `MetadataCapture`/`MetadataRefresh`
 * job intents removed as dead code in f2f4133. Nothing ever wrote a row.
 */
final class DropRawMetadataSnapshots implements MigratesUp
{
    public string $name = '2026_07_02_drop_raw_metadata_snapshots';

    public function up(): QueryStatement
    {
        return new MigrationSqlStatement('DROP TABLE IF EXISTS `raw_metadata_snapshots`');
    }
}
