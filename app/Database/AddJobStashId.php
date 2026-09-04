<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\RawStatement;

final class AddJobStashId implements MigratesUp
{
    public string $name = '2026_09_04_add_job_stash_id';

    public function up(): QueryStatement
    {
        return new RawStatement('ALTER TABLE jobs ADD COLUMN IF NOT EXISTS "stashId" VARCHAR(40) NULL');
    }
}
