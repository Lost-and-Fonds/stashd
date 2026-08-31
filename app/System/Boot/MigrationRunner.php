<?php

declare(strict_types=1);

namespace App\System\Boot;

use Tempest\Database\Database;
use Tempest\Database\Exceptions\QueryWasInvalid;
use Tempest\Database\Migrations\Migration;
use Tempest\Database\Migrations\MigrationManager;
use Tempest\Database\Migrations\RunnableMigrations;

final readonly class MigrationRunner
{
    public function __construct(
        private MigrationManager $migrations,
        private RunnableMigrations $runnableMigrations,
        private Database $database,
        private LegacyBaselineAdopter $baselineAdopter,
    ) {}

    public function run(): void
    {
        $this->baselineAdopter->adopt();
        $this->migrations->up();
    }

    public function hasPendingMigrations(): bool
    {
        $applied = $this->appliedMigrationNames();

        foreach ($this->runnableMigrations->up() as $migration) {
            if (! in_array($migration->name, $applied, strict: true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function appliedMigrationNames(): array
    {
        try {
            /** @var list<Migration> $migrations */
            $migrations = array_values(Migration::all());

            return array_map(
                static fn(Migration $migration): string => $migration->name,
                $migrations,
            );
        } catch (QueryWasInvalid $exception) {
            if ($this->database->dialect->isTableNotFoundError($exception)) {
                return [];
            }

            throw $exception;
        }
    }
}
