<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CreateTableStatement;
use Tempest\Database\QueryStatements\OnDelete;

trait MigrationSchemaHelpers
{
    private function prefixedIdTable(string $table): CreateTableStatement
    {
        return new CreateTableStatement($table)
            ->raw('`id` VARCHAR(40) NOT NULL PRIMARY KEY')
            ->datetime('createdAt', current: true)
            ->datetime('updatedAt', current: true);
    }

    private function prefixedIdTableCreatedOnly(string $table): CreateTableStatement
    {
        return new CreateTableStatement($table)
            ->raw('`id` VARCHAR(40) NOT NULL PRIMARY KEY')
            ->datetime('createdAt', current: true);
    }

    /**
     * Tempest's MigrationManager only merges trailingStatements from the root
     * migration statement, not from CreateTableStatement children inside
     * CompoundStatement — expand indexes/uniques explicitly.
     *
     * @return list<QueryStatement>
     */
    private function tablesWithIndexes(CreateTableStatement ...$tables): array
    {
        $statements = [];

        foreach ($tables as $table) {
            $statements[] = new MigrationSqlStatement($table);
            array_push($statements, ...$table->trailingStatements);
        }

        return array_values(array_filter($statements, static fn(mixed $statement): bool => $statement instanceof QueryStatement));
    }

    /** Inline REFERENCES for SQLite (Tempest strips BelongsToStatement on SQLite). */
    private function fkColumn(
        string $column,
        int $length,
        string $refTable,
        OnDelete $onDelete = OnDelete::RESTRICT,
        bool $nullable = false,
    ): string {
        return sprintf(
            '`%s` VARCHAR(%d) %s REFERENCES `%s`(`id`) ON DELETE %s',
            $column,
            $length,
            $nullable ? 'NULL' : 'NOT NULL',
            $refTable,
            $onDelete->value,
        );
    }
}
