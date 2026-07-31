<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Database\MigrationSqlStatement;
use App\Jobs\JobState;
use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\QueryStatements\CreateTableStatement;

test('migration SQL preserves SQLite output and quotes PostgreSQL identifiers', function (): void {
    $statement = new MigrationSqlStatement('ALTER TABLE `jobs` ADD COLUMN `ownerToken` TEXT NULL');

    expect($statement->compile(DatabaseDialect::SQLITE))
        ->toBe('ALTER TABLE `jobs` ADD COLUMN `ownerToken` TEXT NULL')
        ->and($statement->compile(DatabaseDialect::POSTGRESQL))
        ->toBe('ALTER TABLE "jobs" ADD COLUMN "ownerToken" TEXT NULL');
});

test('migration SQL supports PostgreSQL-specific historical expressions', function (): void {
    $statement = new MigrationSqlStatement(
        "UPDATE `users` SET `username` = substr(`email`, 1, instr(`email`, '@') - 1)",
        "UPDATE \"users\" SET \"username\" = split_part(\"email\", '@', 1)",
    );

    expect($statement->compile(DatabaseDialect::POSTGRESQL))
        ->toBe("UPDATE \"users\" SET \"username\" = split_part(\"email\", '@', 1)");
});

test('migration SQL adapts raw columns inside Tempest table statements', function (): void {
    $statement = new MigrationSqlStatement(
        new CreateTableStatement('jobs')->raw('`ownerToken` TEXT NULL'),
    );

    expect($statement->compile(DatabaseDialect::POSTGRESQL))
        ->toContain('CREATE TABLE "jobs"')
        ->toContain('"ownerToken" TEXT NULL');
});

test('migration SQL keeps PostgreSQL enum columns compatible with SQLite text columns', function (): void {
    $statement = new MigrationSqlStatement(
        new CreateTableStatement('jobs')->enum('state', JobState::class, default: JobState::Pending),
    );

    expect($statement->compile(DatabaseDialect::POSTGRESQL))
        ->toContain('"state" TEXT DEFAULT (\'pending\') NOT NULL')
        ->not->toContain('App\\Jobs\\JobState');
});
