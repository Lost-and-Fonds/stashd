<?php

declare(strict_types=1);

namespace App\Operations\Persistence;

use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Database;
use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\AlterTableStatement;
use Tempest\Database\QueryStatements\CompoundStatement;
use Tempest\Database\QueryStatements\DatetimeStatement;
use Tempest\Database\QueryStatements\RawStatement;
use Tempest\Database\ShouldMigrate;

/**
 * Keep existing checkpoint UUIDs and values while adopting shared model columns.
 */
final class UpgradeCheckpoints implements MigratesUp, ShouldMigrate
{
    public string $name = '2026-11-03_01_upgrade_checkpoints';

    /**
     * Backfill legacy timestamps at migration time; historical publication times were not recorded.
     */
    public function up(): QueryStatement
    {
        return new CompoundStatement(
            new AlterTableStatement('operation_checkpoints')->rename('id', 'uuid'),
            new AlterTableStatement('operation_checkpoints')
                ->add(new DatetimeStatement('created_at', nullable: true))
                ->add(new DatetimeStatement('updated_at', nullable: true)),
            new RawStatement("UPDATE operation_checkpoints SET created_at = date_trunc('second', CURRENT_TIMESTAMP), updated_at = date_trunc('second', CURRENT_TIMESTAMP)"),
            new RawStatement('ALTER TABLE operation_checkpoints ALTER COLUMN created_at SET NOT NULL, ALTER COLUMN updated_at SET NOT NULL'),
        );
    }

    /**
     * Match the PostgreSQL-only Operations schema this migration upgrades.
     */
    public function shouldMigrate(Database $database): bool
    {
        return $database->dialect === DatabaseDialect::POSTGRESQL;
    }
}
