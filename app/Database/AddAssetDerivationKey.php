<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;

final class AddAssetDerivationKey implements MigratesUp
{
    public string $name = '2026_08_22_add_asset_derivation_key';

    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new MigrationSqlStatement('ALTER TABLE `assets` ADD COLUMN `derivationKey` TEXT NULL'),
            new MigrationSqlStatement('CREATE INDEX `assets_derived_identity` ON `assets` (`mediaItemId`, `role`, `derivationKey`)'),
        );
    }
}
