<?php

declare(strict_types=1);

namespace App\Database;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\RawStatement;

final class NormalizeJobTypes implements MigratesUp
{
    public string $name = '2026_09_04_normalize_job_types';

    public function up(): QueryStatement
    {
        return new RawStatement("UPDATE jobs SET intent = 'core.' || intent WHERE intent NOT LIKE '%.%'");
    }
}
