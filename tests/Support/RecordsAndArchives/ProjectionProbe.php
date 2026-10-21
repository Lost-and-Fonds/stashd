<?php

namespace Tests\Support\RecordsAndArchives;

use PDO;
use PHPUnit\Framework\Assert;

/**
 * Independently verify disposable PostgreSQL projection connections and persisted rows.
 *
 * The adapter supplies physical table/column names, never SQL or expected data.
 * No production projection schema or reconciliation behavior is implemented here.
 */
final class ProjectionProbe
{
    /**
     * Reject any connection outside the parent-selected disposable database and role.
     *
     * Validation precedes PDO creation. Server identity, database ownership and
     * an unprivileged role without memberships are checked before destructive work.
     */
    public static function connect(array $projection, string $name): PDO
    {
        Assert::assertMatchesRegularExpression('/^stashd_tx_test_[a-f0-9]{16}$/D', $name);
        Assert::assertSame('pgsql:host=lerd-postgres;port=5432;dbname=' . $name, $projection['dsn']);
        Assert::assertSame($name, $projection['username']);
        Assert::assertSame($name, $projection['schema']);
        $database = new PDO($projection['dsn'], $projection['username'], $projection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $identity = $database->query('SELECT current_database() AS database, current_user AS username,
            (SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = current_database()) AS owner,
            (SELECT rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls
             FROM pg_roles WHERE rolname = current_user) AS privileged')->fetch(PDO::FETCH_ASSOC);
        Assert::assertSame($name, $identity['database']);
        Assert::assertSame($name, $identity['username']);
        Assert::assertSame($name, $identity['owner']);
        Assert::assertFalse($identity['privileged']);
        Assert::assertSame(0, (int) $database->query('SELECT count(*) FROM pg_auth_members
            WHERE member = (SELECT oid FROM pg_roles WHERE rolname = current_user)')->fetchColumn());
        return $database;
    }

    /**
     * Query actual base tables and require the exact rebuilt Item and Asset relationships.
     *
     * Expected pins come from independently verified OCFL bytes, not the rebuild
     * response. Empty tables and response-only fabricated projections fail.
     */
    public static function assertRebuilt(PDO $database, array $projection, array $item, array $pins): void
    {
        Assert::assertSame(
            [['object_id' => $item['object_id'], 'version' => $item['version']]],
            self::rows($database, $projection['schema'], $projection['tables']['items'], ['object_id', 'version']),
        );
        $expected = [];
        foreach ($pins as $pin) {
            $expected[] = [
                'item_object_id' => $item['object_id'],
                'item_version' => $item['version'],
                'asset_object_id' => $pin['object_id'],
                'asset_version' => $pin['version'],
            ];
        }
        $actual = self::rows($database, $projection['schema'], $projection['tables']['pins'], ['item_object_id', 'item_version', 'asset_object_id', 'asset_version']);
        sort($expected);
        sort($actual);
        Assert::assertSame($expected, $actual);
    }

    /**
     * Select named columns from a real base table without accepting adapter-provided SQL.
     *
     * Identifier validation prevents expressions, injected statements and constant
     * SELECT witnesses. Views cannot substitute for persisted projection rows.
     */
    private static function rows(PDO $database, string $schema, array $table, array $fields): array
    {
        $exists = $database->prepare("SELECT count(*) FROM information_schema.tables
            WHERE table_schema = ? AND table_name = ? AND table_type = 'BASE TABLE'");
        $exists->execute([$schema, $table['name']]);
        Assert::assertSame(1, (int) $exists->fetchColumn());
        $columns = [];
        foreach ($fields as $field) {
            $columns[] = self::identifier($table['columns'][$field]) . ' AS ' . self::identifier($field);
        }
        return $database->query('SELECT ' . implode(', ', $columns) . ' FROM ' . self::identifier($schema) . '.' . self::identifier($table['name']))->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Quote one plain PostgreSQL identifier after excluding SQL syntax and expressions.
     */
    private static function identifier(string $value): string
    {
        Assert::assertMatchesRegularExpression('/^[a-z_][a-z0-9_]*$/D', $value);
        return '"' . $value . '"';
    }
}
