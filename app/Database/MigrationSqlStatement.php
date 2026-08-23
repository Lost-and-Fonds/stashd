<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\QueryStatement;

/** Compiles a migration statement for the PostgreSQL schema. */
final readonly class MigrationSqlStatement implements QueryStatement
{
    public function __construct(private string|QueryStatement $statement) {}

    public function compile(DatabaseDialect $_dialect): string
    {
        $sql = str_replace(
            '`',
            '"',
            $this->statement instanceof QueryStatement
                ? $this->statement->compile(DatabaseDialect::POSTGRESQL)
                : $this->statement,
        );

        return preg_replace('/"[^"]*\\\\[^"]*"/', 'TEXT', $sql) ?? $sql;
    }
}
