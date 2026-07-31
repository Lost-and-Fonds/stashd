<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Database;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Query;

use function Tempest\Database\query;

use Tempest\DateTime\DateTime;
use Tempest\DateTime\FormatPattern;
use Tempest\DateTime\Timezone;
use Tests\Fixtures\Mapping\TempestMappingCamelCaseDateTimeRecord;
use Tests\Fixtures\Mapping\TempestMappingCamelCaseRecord;
use Tests\Fixtures\Mapping\TempestMappingSnakeCaseRecord;
use Tests\Fixtures\Mapping\TempestMappingTestRecord;
use Tests\Fixtures\Mapping\TempestMappingTestRecordWithMapAttributes;
use Tests\IntegrationTestCase;

/**
 * Proof-of-behavior spike for Tempest column ↔ property naming.
 *
 * These tests document how Tempest v3 maps model properties to SQL identifiers.
 * Keep them when changing database conventions.
 */
final class TempestColumnMappingSpikeTest extends IntegrationTestCase
{
    protected Database $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTestingDatabase();
        $this->db = $this->container->get(Database::class);

        // SQLite-only on purpose: these assertions pin SQLite's own error text
        // ("no such column") and its integer/boolean coercion, and the fixture
        // DDL uses SQLite storage classes. PostgreSQL column mapping is proven
        // separately by TempestPostgresCompatibilityTest.
        if ($this->db->dialect === DatabaseDialect::POSTGRESQL) {
            $this->markTestSkipped('SQLite-specific mapping spike; see TempestPostgresCompatibilityTest for PostgreSQL.');
        }

        $this->createFixtureTables();
    }

    protected function tearDown(): void
    {
        $this->db->execute(new Query('DROP TABLE IF EXISTS tempest_mapping_test'));
        $this->db->execute(new Query('DROP TABLE IF EXISTS tempest_mapping_camel'));
        parent::tearDown();
    }

    public function test_snake_case_table_stores_snake_case_columns(): void
    {
        $this->seedSnakeRow('map_raw_1');

        $row = $this->db->fetchFirst(new Query(
            'SELECT id, created_at, supports_hardlinks, progress_percent, last_checked_at
             FROM tempest_mapping_test WHERE id = ?',
            bindings: ['map_raw_1'],
        ));

        expect($row)->toBeArray()
            ->and($row)->toHaveKeys(['created_at', 'supports_hardlinks', 'progress_percent', 'last_checked_at'])
            ->and($row['supports_hardlinks'])->toBe(1);
    }

    public function test_camel_case_properties_query_camel_case_columns_in_select_sql(): void
    {
        $this->seedSnakeRow('map_model_1');

        try {
            TempestMappingTestRecord::select()
                ->where('id', 'map_model_1')
                ->first();
            $message = '';
        } catch (\Throwable $exception) {
            $message = $exception->getMessage();
        }

        expect($message)->toContain('no such column')
            ->and($message)->toContain('createdAt');
    }

    public function test_camel_case_properties_use_camel_case_columns_on_insert(): void
    {
        $record = new TempestMappingTestRecord(
            createdAt: '2026-06-16 12:00:00',
            supportsHardlinks: true,
            progressPercent: 10.0,
            lastCheckedAt: '2026-06-16 12:05:00',
        );
        $record->id = new PrimaryKey('map_insert_1');

        try {
            query(TempestMappingTestRecord::class)->insert($record)->execute();
            $message = '';
        } catch (\Throwable $exception) {
            $message = $exception->getMessage();
        }

        expect($message)->toContain('no column named createdAt');
    }

    public function test_map_from_attributes_do_not_change_database_select_sql(): void
    {
        $this->seedSnakeRow('map_attrs_1');

        try {
            TempestMappingTestRecordWithMapAttributes::select()
                ->where('id', 'map_attrs_1')
                ->first();
            $message = '';
        } catch (\Throwable $exception) {
            $message = $exception->getMessage();
        }

        expect($message)->toContain('no such column')
            ->and($message)->toContain('createdAt');
    }

    public function test_map_from_attributes_do_not_change_database_insert_sql(): void
    {
        $record = new TempestMappingTestRecordWithMapAttributes(
            createdAt: '2026-06-16 12:00:00',
            supportsHardlinks: true,
            progressPercent: 10.0,
            lastCheckedAt: '2026-06-16 12:05:00',
        );
        $record->id = new PrimaryKey('map_attrs_insert_1');

        try {
            query(TempestMappingTestRecordWithMapAttributes::class)->insert($record)->execute();
            $failed = false;
        } catch (\Throwable) {
            $failed = true;
        }

        expect($failed)->toBeTrue();
    }

    public function test_array_insert_can_write_snake_case_columns_but_model_select_still_fails(): void
    {
        query(TempestMappingTestRecordWithMapAttributes::class)->insert([
            'id' => 'map_array_1',
            'created_at' => '2026-06-16 12:00:00',
            'supports_hardlinks' => 1,
            'progress_percent' => 75.0,
            'last_checked_at' => '2026-06-16 12:05:00',
        ])->execute();

        $row = $this->db->fetchFirst(new Query(
            'SELECT supports_hardlinks, progress_percent FROM tempest_mapping_test WHERE id = ?',
            bindings: ['map_array_1'],
        ));

        expect($row['supports_hardlinks'])->toBe(1)
            ->and($row['progress_percent'])->toBe(75.0);

        try {
            TempestMappingTestRecordWithMapAttributes::select()
                ->where('id', 'map_array_1')
                ->first();
            $message = '';
        } catch (\Throwable $exception) {
            $message = $exception->getMessage();
        }

        expect($message)->toContain('no such column')
            ->and($message)->toContain('createdAt');
    }

    public function test_snake_case_properties_round_trip_against_snake_case_columns(): void
    {
        $record = new TempestMappingSnakeCaseRecord(
            created_at: '2026-06-16 12:00:00',
            supports_hardlinks: true,
            progress_percent: 42.5,
            last_checked_at: '2026-06-16 12:05:00',
        );
        $record->id = new PrimaryKey('map_snake_1');

        query(TempestMappingSnakeCaseRecord::class)->insert($record)->execute();

        $loaded = TempestMappingSnakeCaseRecord::select()
            ->where('id', 'map_snake_1')
            ->first();

        expect($loaded)->not->toBeNull()
            ->and($loaded->created_at)->toBe('2026-06-16 12:00:00')
            ->and($loaded->supports_hardlinks)->toBeTrue()
            ->and($loaded->progress_percent)->toBe(42.5)
            ->and($loaded->last_checked_at)->toBe('2026-06-16 12:05:00');

        $loaded->progress_percent = 88.0;
        $loaded->save();

        $reloaded = TempestMappingSnakeCaseRecord::select()
            ->where('id', 'map_snake_1')
            ->first();

        expect($reloaded?->progress_percent)->toBe(88.0);
    }

    public function test_camel_case_properties_round_trip_against_camel_case_columns(): void
    {
        $record = new TempestMappingCamelCaseRecord(
            createdAt: '2026-06-16 12:00:00',
            supportsHardlinks: true,
            progressPercent: 42.5,
            lastCheckedAt: '2026-06-16 12:05:00',
        );
        $record->id = new PrimaryKey('map_camel_1');

        query(TempestMappingCamelCaseRecord::class)->insert($record)->execute();

        $loaded = TempestMappingCamelCaseRecord::select()
            ->where('id', 'map_camel_1')
            ->first();

        expect($loaded)->not->toBeNull()
            ->and($loaded->createdAt)->toBe('2026-06-16 12:00:00')
            ->and($loaded->supportsHardlinks)->toBeTrue()
            ->and($loaded->progressPercent)->toBe(42.5);

        $loaded->progressPercent = 91.0;
        $loaded->save();

        $reloaded = TempestMappingCamelCaseRecord::select()
            ->where('id', 'map_camel_1')
            ->first();

        expect($reloaded?->progressPercent)->toBe(91.0);
    }

    public function test_tempest_datetime_properties_round_trip_against_camel_case_columns(): void
    {
        $record = new TempestMappingCamelCaseDateTimeRecord(
            createdAt: DateTime::parse('2026-06-16T12:00:00+00:00', Timezone::UTC),
            supportsHardlinks: true,
            progressPercent: 42.5,
            lastCheckedAt: DateTime::parse('2026-06-16T12:05:00+00:00', Timezone::UTC),
        );
        $record->id = new PrimaryKey('map_datetime_1');

        query(TempestMappingCamelCaseDateTimeRecord::class)->insert($record)->execute();

        $row = $this->db->fetchFirst(new Query(
            'SELECT createdAt, lastCheckedAt FROM tempest_mapping_camel WHERE id = ?',
            bindings: ['map_datetime_1'],
        ));

        expect($row)->toBeArray()
            ->and($row['createdAt'])->toBe('2026-06-16 12:00:00')
            ->and($row['lastCheckedAt'])->toBe('2026-06-16 12:05:00');

        $loaded = TempestMappingCamelCaseDateTimeRecord::select()
            ->where('id', 'map_datetime_1')
            ->first();

        expect($loaded)->not->toBeNull()
            ->and($loaded->createdAt)->toBeInstanceOf(DateTime::class)
            ->and($loaded->createdAt->format(FormatPattern::SQL_DATE_TIME, Timezone::UTC))->toBe('2026-06-16 12:00:00')
            ->and($loaded->lastCheckedAt)->toBeInstanceOf(DateTime::class)
            ->and($loaded->lastCheckedAt?->format(FormatPattern::SQL_DATE_TIME, Timezone::UTC))->toBe('2026-06-16 12:05:00');

        $loaded->lastCheckedAt = DateTime::parse('2026-06-16T12:15:00+00:00', Timezone::UTC);
        $loaded->save();

        $reloaded = TempestMappingCamelCaseDateTimeRecord::select()
            ->where('id', 'map_datetime_1')
            ->first();

        expect($reloaded?->lastCheckedAt?->format(FormatPattern::SQL_DATE_TIME, Timezone::UTC))->toBe('2026-06-16 12:15:00');
    }

    private function createFixtureTables(): void
    {
        $this->db->execute(new Query('DROP TABLE IF EXISTS tempest_mapping_test'));
        $this->db->execute(new Query('DROP TABLE IF EXISTS tempest_mapping_camel'));

        $this->db->execute(new Query(
            'CREATE TABLE tempest_mapping_test (
                id TEXT NOT NULL PRIMARY KEY,
                created_at TEXT NOT NULL,
                supports_hardlinks INTEGER NOT NULL DEFAULT 0,
                progress_percent REAL,
                last_checked_at TEXT
            )',
        ));

        $this->db->execute(new Query(
            'CREATE TABLE tempest_mapping_camel (
                id TEXT NOT NULL PRIMARY KEY,
                createdAt TEXT NOT NULL,
                supportsHardlinks INTEGER NOT NULL DEFAULT 0,
                progressPercent REAL,
                lastCheckedAt TEXT
            )',
        ));
    }

    private function seedSnakeRow(string $id): void
    {
        $this->db->execute(new Query(
            'INSERT INTO tempest_mapping_test (id, created_at, supports_hardlinks, progress_percent, last_checked_at)
             VALUES (?, ?, ?, ?, ?)',
            bindings: [$id, '2026-06-16 12:00:00', 1, 42.5, '2026-06-16 12:05:00'],
        ));
    }
}
