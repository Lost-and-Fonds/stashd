<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Database;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Query;

use function Tempest\Database\query;

use Tempest\Database\QueryStatements\CreateTableStatement;
use Tempest\Database\QueryStatements\DropTableStatement;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\FormatPattern;
use Tempest\DateTime\Timezone;
use Tests\Fixtures\Mapping\TempestMappingCamelCaseDateTimeRecord;
use Tests\IntegrationTestCase;

final class TempestPostgresCompatibilityTest extends IntegrationTestCase
{
    private Database $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTestingDatabase();
        $this->db = $this->container->get(Database::class);

        if ($this->db->dialect !== DatabaseDialect::POSTGRESQL) {
            $this->markTestSkipped('Run with DB_CONNECTION=pgsql to exercise PostgreSQL compatibility.');
        }

        $this->db->execute(new Query(new DropTableStatement('tempest_mapping_camel')));

        $table = new CreateTableStatement('tempest_mapping_camel')
            ->string('id', length: 40)
            ->datetime('createdAt')
            ->boolean('supportsHardlinks', default: false)
            ->float('progressPercent', nullable: true)
            ->datetime('lastCheckedAt', nullable: true)
            ->unique('id');

        $this->db->execute(new Query($table));

        foreach ($table->trailingStatements as $statement) {
            $this->db->execute(new Query($statement));
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->dialect === DatabaseDialect::POSTGRESQL) {
            $this->db->execute(new Query(new DropTableStatement('tempest_mapping_camel')));
        }

        parent::tearDown();
    }

    public function test_tempest_round_trips_camel_case_columns_on_postgres(): void
    {
        $record = new TempestMappingCamelCaseDateTimeRecord(
            createdAt: DateTime::parse('2026-06-16T12:00:00+00:00', Timezone::UTC),
            supportsHardlinks: true,
            progressPercent: 42.5,
            lastCheckedAt: DateTime::parse('2026-06-16T12:05:00+00:00', Timezone::UTC),
        );
        $record->id = new PrimaryKey('map_postgres_1');

        query(TempestMappingCamelCaseDateTimeRecord::class)->insert($record)->execute();

        $columns = $this->db->fetch(new Query(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position',
            bindings: ['tempest_mapping_camel'],
        ));
        $loaded = TempestMappingCamelCaseDateTimeRecord::select()
            ->where('id', 'map_postgres_1')
            ->first();

        expect(array_column($columns, 'column_name'))->toBe([
            'id',
            'createdAt',
            'supportsHardlinks',
            'progressPercent',
            'lastCheckedAt',
        ])->and($loaded)->not->toBeNull()
            ->and($loaded->createdAt->format(FormatPattern::SQL_DATE_TIME, Timezone::UTC))->toBe('2026-06-16 12:00:00')
            ->and($loaded->supportsHardlinks)->toBeTrue()
            ->and($loaded->progressPercent)->toBe(42.5)
            ->and($loaded->lastCheckedAt?->format(FormatPattern::SQL_DATE_TIME, Timezone::UTC))->toBe('2026-06-16 12:05:00');

        $loaded->progressPercent = 91.0;
        $loaded->save();

        expect(TempestMappingCamelCaseDateTimeRecord::findById(new PrimaryKey('map_postgres_1'))?->progressPercent)
            ->toBe(91.0);
    }
}
