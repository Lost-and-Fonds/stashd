<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\CompoundStatement;
use Tempest\Database\QueryStatements\OnDelete;

final class CreatePreservationEvents implements MigratesUp
{
    use MigrationSchemaHelpers;

    public string $name = '2026_09_07_create_preservation_events';

    public function up(): QueryStatement
    {
        $table = $this->prefixedIdTableCreatedOnly('preservation_events')
            ->raw($this->fkColumn('assetId', 40, 'assets', OnDelete::CASCADE))
            ->string('eventType')
            ->string('outcome')
            ->datetime('occurredAt')
            ->string('expectedChecksum', nullable: true)
            ->string('observedChecksum', nullable: true)
            ->string('jobId', nullable: true)
            ->string('agent', nullable: true)
            ->text('detail', nullable: true)
            ->index('assetId')
            ->index('occurredAt');

        return new CompoundStatement(
            ...$this->tablesWithIndexes($table),
        );
    }
}
