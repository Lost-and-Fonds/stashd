<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;
use Tempest\Database\QueryStatements\OnDelete;

final class CreateAssetAvailabilityObservations implements MigratesUp
{
    use MigrationSchemaHelpers;

    public string $name = '2026_09_10_create_asset_availability_observations';

    public function up(): QueryStatement
    {
        $table = $this->prefixedIdTable('asset_availability_observations')
            ->raw($this->fkColumn('itemId', 40, 'items', OnDelete::CASCADE))
            ->string('role')
            ->string('kind')
            ->string('providerVersion')
            ->raw('`permanent` BOOLEAN NOT NULL')
            ->text('message')
            ->datetime('observedAt')
            ->index('itemId');

        return new CompoundStatement(...$this->tablesWithIndexes($table));
    }
}
