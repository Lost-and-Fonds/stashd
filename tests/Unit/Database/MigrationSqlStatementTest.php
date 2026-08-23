<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Database\MigrationSqlStatement;
use App\Jobs\JobState;
use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\QueryStatements\CreateTableStatement;

test('migration SQL quotes PostgreSQL identifiers', function (): void {
    $statement = new MigrationSqlStatement('ALTER TABLE `jobs` ADD COLUMN `ownerToken` TEXT NULL');

    expect($statement->compile(DatabaseDialect::POSTGRESQL))
        ->toBe('ALTER TABLE "jobs" ADD COLUMN "ownerToken" TEXT NULL');
});

test('migration SQL adapts raw columns and enum values for PostgreSQL', function (): void {
    $columns = new MigrationSqlStatement(
        new CreateTableStatement('jobs')->raw('`ownerToken` TEXT NULL'),
    );
    $enum = new MigrationSqlStatement(
        new CreateTableStatement('jobs')->enum('state', JobState::class, default: JobState::Pending),
    );

    expect($columns->compile(DatabaseDialect::POSTGRESQL))
        ->toContain('CREATE TABLE "jobs"')
        ->toContain('"ownerToken" TEXT NULL')
        ->and($enum->compile(DatabaseDialect::POSTGRESQL))
        ->toContain('"state" TEXT DEFAULT (\'pending\') NOT NULL')
        ->not->toContain('App\\Jobs\\JobState');
});
