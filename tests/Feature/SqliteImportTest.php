<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\SqliteImporter;
use PDO;
use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Database;
use Tempest\Database\Query;

/**
 * Proves the SQLite -> PostgreSQL upgrade importer on its own tables rather than
 * the domain schema, so it tests the importer's mechanics (dependency ordering,
 * column intersection, count verification, refusal to merge) instead of
 * re-asserting migrations.
 *
 * `import_child` sorts before `import_parent` alphabetically and holds the
 * foreign key, so a copy in name order would fail: the ordering is load-bearing.
 */
beforeEach(function (): void {
    $this->db = $this->container->get(Database::class);

    if ($this->db->dialect !== DatabaseDialect::POSTGRESQL) {
        $this->markTestSkipped('The importer only targets PostgreSQL.');
    }

    $this->db->execute(new Query('DROP TABLE IF EXISTS import_child'));
    $this->db->execute(new Query('DROP TABLE IF EXISTS import_parent'));
    $this->db->execute(new Query(
        'CREATE TABLE import_parent ("id" VARCHAR(40) NOT NULL PRIMARY KEY, "createdAt" TIMESTAMP NOT NULL)',
    ));
    $this->db->execute(new Query(
        'CREATE TABLE import_child (
            "id" VARCHAR(40) NOT NULL PRIMARY KEY,
            "parentId" VARCHAR(40) NOT NULL REFERENCES import_parent("id"),
            "label" VARCHAR(255) NULL
        )',
    ));

    $this->legacyPath = sys_get_temp_dir() . '/stashd-import-' . bin2hex(random_bytes(4)) . '.sqlite';
    $legacy = new PDO('sqlite:' . $this->legacyPath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $legacy->exec('CREATE TABLE import_parent (`id` TEXT NOT NULL PRIMARY KEY, `createdAt` TEXT NOT NULL)');
    $legacy->exec(
        'CREATE TABLE import_child (
            `id` TEXT NOT NULL PRIMARY KEY,
            `parentId` TEXT NOT NULL REFERENCES import_parent(`id`),
            `label` TEXT NULL,
            `droppedColumn` TEXT NULL
        )',
    );
    $legacy->exec("INSERT INTO import_parent VALUES ('parent_1', '2026-07-30 10:00:00')");
    $legacy->exec("INSERT INTO import_parent VALUES ('parent_2', '2026-07-30 11:00:00')");
    $legacy->exec("INSERT INTO import_child VALUES ('child_1', 'parent_1', 'first', 'gone')");
    $legacy->exec("INSERT INTO import_child VALUES ('child_2', 'parent_2', NULL, 'gone')");
});

afterEach(function (): void {
    if (($this->legacyPath ?? null) !== null && is_file($this->legacyPath)) {
        @unlink($this->legacyPath);
    }

    if (($this->db ?? null) !== null && $this->db->dialect === DatabaseDialect::POSTGRESQL) {
        $this->db->execute(new Query('DROP TABLE IF EXISTS import_child'));
        $this->db->execute(new Query('DROP TABLE IF EXISTS import_parent'));
    }
});

test('importer copies a legacy database in foreign key order', function (): void {
    $copied = $this->container->get(SqliteImporter::class)->import($this->legacyPath);

    // Parent must be copied first or the child's foreign key would be violated.
    expect(array_keys($copied))->toBe(['import_parent', 'import_child'])
        ->and($copied['import_parent'])->toBe(2)
        ->and($copied['import_child'])->toBe(2);

    $children = $this->db->fetch(new Query('SELECT "id", "parentId", "label" FROM import_child ORDER BY "id"'));

    expect($children)->toHaveCount(2)
        ->and($children[0]['id'])->toBe('child_1')
        ->and($children[0]['parentId'])->toBe('parent_1')
        ->and($children[0]['label'])->toBe('first')
        // A column the legacy file has but PostgreSQL dropped is skipped, not fatal.
        ->and($children[1]['label'])->toBeNull();
});

test('importer refuses to merge into a database that already holds rows', function (): void {
    $importer = $this->container->get(SqliteImporter::class);
    $importer->import($this->legacyPath);

    expect(fn() => $importer->import($this->legacyPath))
        ->toThrow(\RuntimeException::class, 'Refusing to merge');

    // The first import's rows are still intact after the refusal.
    expect($this->db->fetch(new Query('SELECT "id" FROM import_parent')))->toHaveCount(2);
});

test('importer rejects a missing legacy database', function (): void {
    expect(fn() => $this->container->get(SqliteImporter::class)->import('/nonexistent/stashd.sqlite'))
        ->toThrow(\RuntimeException::class, 'does not exist or is not readable');
});
