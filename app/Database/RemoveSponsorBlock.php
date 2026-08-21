<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;

final class RemoveSponsorBlock implements MigratesUp
{
    public string $name = '2026_08_21_remove_sponsorblock';

    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new MigrationSqlStatement("DELETE FROM `media_timeline_entries` WHERE CAST(`source` AS TEXT) = 'sponsorblock' OR CAST(`kind` AS TEXT) = 'segment'"),
            new MigrationSqlStatement('DROP TABLE IF EXISTS `broadcast_sponsorblock_refreshes`'),
        );
    }
}
