<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;

final class NormalizeLegacyAssetRoles implements MigratesUp
{
    public string $name = '2026_08_23_normalize_legacy_asset_roles';

    public function up(): QueryStatement
    {
        return new MigrationSqlStatement(
            "UPDATE `assets` SET `role` = 'derived' WHERE `role` = 'podcast_audio'",
        );
    }
}
