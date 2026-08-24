<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;
use Tempest\Database\QueryStatements\RawStatement;

/**
 * PostgreSQL schema supported for direct upgrades from Stashd 83660a7.
 *
 * Keep the companion SQL literal and self-contained: it is the migration
 * baseline, not a projection of current application types.
 */
final class SupportedPostgresBaseline implements MigratesUp
{
    public const NAME = '2026_08_20_supported_postgres_baseline';

    public const HASH = 'd3c4ba6d791547eadfe77abf8339f841';

    public string $name = self::NAME;

    public function up(): QueryStatement
    {
        $sql = file_get_contents(__DIR__ . '/../../database/schema/supported-postgres-baseline.sql');

        if ($sql === false) {
            throw new \RuntimeException('Supported PostgreSQL baseline schema is missing.');
        }

        $statements = array_filter(
            preg_split('/;\s*(?=--|$)/m', $sql, flags: PREG_SPLIT_NO_EMPTY) ?: [],
            static fn(string $statement): bool => preg_match('/\b(?:ALTER|CREATE)\b/i', $statement) === 1,
        );

        return new CompoundStatement(
            ...array_map(static fn(string $statement): RawStatement => new RawStatement($statement), $statements ?: []),
        );
    }
}
