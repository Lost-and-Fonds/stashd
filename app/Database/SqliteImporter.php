<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOStatement;
use RuntimeException;
use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Database;
use Tempest\Database\Query;
use Tempest\Database\Transactions\TransactionManager;
use Throwable;

/**
 * Copies a legacy SQLite database into the PostgreSQL schema Tempest migrations
 * already created.
 *
 * This is a one-shot upgrade path, not a sync: it refuses to run against a
 * PostgreSQL database that already holds rows. The SQLite file is opened
 * read-only and never written, so it stays usable as the rollback artifact.
 */
final readonly class SqliteImporter
{
    /**
     * Tempest owns migration history on the PostgreSQL side -- the schema is
     * migrated before the import runs, so copying the legacy rows would only
     * duplicate them.
     */
    private const array SKIPPED_TABLES = ['migrations'];

    public function __construct(
        private Database $database,
        private TransactionManager $transactions,
    ) {
    }

    /**
     * @return array<string, int> table name => rows copied, in the order copied
     */
    public function import(string $sqlitePath, bool $force = false): array
    {
        if ($this->database->dialect !== DatabaseDialect::POSTGRESQL) {
            throw new RuntimeException('The importer only targets PostgreSQL. Set DB_CONNECTION=pgsql before importing.');
        }

        if (! is_file($sqlitePath) || ! is_readable($sqlitePath)) {
            throw new RuntimeException("Legacy SQLite database [{$sqlitePath}] does not exist or is not readable.");
        }

        $legacy = $this->openReadOnly($sqlitePath);
        $tables = $this->copyOrder($legacy);

        if ($tables === []) {
            throw new RuntimeException("Legacy SQLite database [{$sqlitePath}] contains no importable tables.");
        }

        $occupied = [];

        foreach ($tables as $table) {
            if ($this->postgresCount($table) > 0) {
                $occupied[] = $table;
            }
        }

        if ($occupied !== [] && ! $force) {
            throw new RuntimeException(sprintf(
                'The PostgreSQL database already holds rows in: %s. Refusing to merge two databases. Start from an empty database, or pass --force if you are certain.',
                implode(', ', $occupied),
            ));
        }

        $copied = [];

        // One transaction for the whole import: a failure anywhere leaves
        // PostgreSQL exactly as it was and SQLite untouched, so it can be retried.
        //
        // Deliberately not Database::withinTransaction() -- that catches
        // Throwable and returns a bool, which would leave the operator with
        // "the import failed" and no cause. The real exception matters here.
        $this->transactions->begin();

        try {
            foreach ($tables as $table) {
                $copied[$table] = $this->copyTable($legacy, $table);
            }

            $this->verifyCounts($legacy, $copied);
            $this->transactions->commit();
        } catch (Throwable $throwable) {
            $this->transactions->rollback();

            throw new RuntimeException(
                sprintf('Import failed and was rolled back; PostgreSQL is unchanged. %s', $throwable->getMessage()),
                previous: $throwable,
            );
        }

        return $copied;
    }

    private function openReadOnly(string $path): PDO
    {
        return new PDO(
            'sqlite:file:' . $path . '?mode=ro',
            options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );
    }

    /**
     * Orders tables so a row is always inserted after whatever it references.
     * Derived from the legacy schema's own foreign keys rather than from creation
     * order, because a later migration can rebuild a table and move it after
     * tables that point at it.
     *
     * @return list<string>
     */
    private function copyOrder(PDO $legacy): array
    {
        $tables = [];

        foreach ($this->legacyRows($legacy, "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $row) {
            $name = $this->stringField($row, 'name');

            if ($name === null || in_array($name, self::SKIPPED_TABLES, true)) {
                continue;
            }

            // A table PostgreSQL does not have cannot be imported into it.
            if ($this->postgresColumns($name) !== []) {
                $tables[] = $name;
            }
        }

        $dependencies = [];

        foreach ($tables as $table) {
            $referenced = [];

            foreach ($this->legacyRows($legacy, sprintf('PRAGMA foreign_key_list(%s)', $this->quoteIdentifier($table))) as $row) {
                $target = $this->stringField($row, 'table');

                // Self-references need no ordering, and a table we are not
                // importing cannot constrain the order either.
                if ($target !== null && $target !== $table && in_array($target, $tables, true)) {
                    $referenced[] = $target;
                }
            }

            $dependencies[$table] = array_values(array_unique($referenced));
        }

        $ordered = [];
        $remaining = $tables;

        while ($remaining !== []) {
            $ready = array_values(array_filter(
                $remaining,
                static fn (string $table): bool => array_all(
                    $dependencies[$table],
                    static fn (string $referenced): bool => in_array($referenced, $ordered, true),
                ),
            ));

            if ($ready === []) {
                // A dependency cycle cannot be ordered; copy what is left in a
                // stable order and let PostgreSQL reject it loudly rather than
                // silently importing half a database.
                array_push($ordered, ...$remaining);

                break;
            }

            array_push($ordered, ...$ready);
            $remaining = array_values(array_diff($remaining, $ready));
        }

        return $ordered;
    }

    private function copyTable(PDO $legacy, string $table): int
    {
        // Only columns present on both sides: an older SQLite file can carry
        // columns a later migration dropped.
        $columns = array_values(array_intersect(
            $this->legacyColumns($legacy, $table),
            $this->postgresColumns($table),
        ));

        if ($columns === []) {
            return 0;
        }

        $insert = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map($this->quoteIdentifier(...), $columns)),
            implode(', ', array_fill(0, count($columns), '?')),
        );

        $select = $this->legacyStatement($legacy, sprintf(
            'SELECT %s FROM %s',
            implode(', ', array_map($this->quoteIdentifier(...), $columns)),
            $this->quoteIdentifier($table),
        ));

        $rows = 0;

        while (($row = $select->fetch()) !== false) {
            if (! is_array($row)) {
                continue;
            }

            $bindings = [];

            foreach ($columns as $column) {
                $bindings[] = $row[$column] ?? null;
            }

            $this->database->execute(new Query($insert, $bindings));
            $rows++;
        }

        return $rows;
    }

    /** @param array<string, int> $copied */
    private function verifyCounts(PDO $legacy, array $copied): void
    {
        foreach ($copied as $table => $expected) {
            $source = $this->legacyCount($legacy, $table);
            $target = $this->postgresCount($table);

            if ($source !== $target) {
                throw new RuntimeException(
                    "Row count mismatch for [{$table}]: SQLite has {$source}, PostgreSQL received {$target}. Rolling back.",
                );
            }
        }
    }

    /** @return list<string> */
    private function legacyColumns(PDO $legacy, string $table): array
    {
        $columns = [];

        foreach ($this->legacyRows($legacy, sprintf('PRAGMA table_info(%s)', $this->quoteIdentifier($table))) as $row) {
            $name = $this->stringField($row, 'name');

            if ($name !== null) {
                $columns[] = $name;
            }
        }

        return $columns;
    }

    /** @return list<string> */
    private function postgresColumns(string $table): array
    {
        $columns = [];

        $rows = $this->database->fetch(new Query(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?',
            bindings: [$table],
        ));

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = $this->stringField($row, 'column_name');

            if ($name !== null) {
                $columns[] = $name;
            }
        }

        return $columns;
    }

    private function legacyCount(PDO $legacy, string $table): int
    {
        $value = $this->legacyStatement($legacy, sprintf(
            'SELECT COUNT(*) FROM %s',
            $this->quoteIdentifier($table),
        ))->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }

    private function postgresCount(string $table): int
    {
        $row = $this->database->fetchFirst(new Query(sprintf(
            'SELECT COUNT(*) AS count FROM %s',
            $this->quoteIdentifier($table),
        )));

        $value = is_array($row) ? ($row['count'] ?? 0) : 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /** @return list<array<mixed, mixed>> */
    private function legacyRows(PDO $legacy, string $sql): array
    {
        $rows = [];

        foreach ($this->legacyStatement($legacy, $sql)->fetchAll() as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function legacyStatement(PDO $legacy, string $sql): PDOStatement
    {
        $statement = $legacy->query($sql);

        if ($statement === false) {
            throw new RuntimeException("Could not read the legacy database: [{$sql}].");
        }

        return $statement;
    }

    /** @param array<mixed, mixed> $row */
    private function stringField(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_string($value) || is_int($value) ? (string) $value : null;
    }

    /**
     * Both engines accept double-quoted identifiers, and PostgreSQL needs them
     * for Stashd's camelCase columns.
     */
    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
