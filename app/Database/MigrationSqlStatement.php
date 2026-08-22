<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\QueryStatement;

/** Preserves legacy SQLite migration SQL while adapting identifiers for PostgreSQL. */
final readonly class MigrationSqlStatement implements QueryStatement
{
    public function __construct(
        private string|QueryStatement $sqlite,
        private ?string $postgres = null,
    ) {}

    public function compile(DatabaseDialect $dialect): string
    {
        if ($dialect === DatabaseDialect::POSTGRESQL) {
            $sql = $this->postgres ?? str_replace('`', '"', $this->sql($dialect));

            return preg_replace('/"[^"]*\\\\[^"]*"/', 'TEXT', $sql) ?? $sql;
        }

        return $this->sql($dialect);
    }

    private function sql(DatabaseDialect $dialect): string
    {
        return $this->sqlite instanceof QueryStatement
            ? $this->sqlite->compile($dialect)
            : $this->sqlite;
    }
}
