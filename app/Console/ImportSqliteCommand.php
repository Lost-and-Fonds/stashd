<?php

declare(strict_types=1);

namespace App\Console;

use App\Database\SqliteImporter;
use Tempest\Console\ConsoleArgument;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\ExitCode;
use Tempest\Console\HasConsole;

final readonly class ImportSqliteCommand
{
    use HasConsole;

    public function __construct(
        private SqliteImporter $importer,
    ) {
    }

    #[ConsoleCommand(
        name: 'stashd:import-sqlite',
        description: 'Copy a legacy SQLite database into PostgreSQL once, when upgrading an existing install.',
    )]
    public function __invoke(
        #[ConsoleArgument(description: 'Path to the legacy SQLite database, e.g. /data/stashd.sqlite')]
        string $path,
        #[ConsoleArgument(description: 'Import even though the PostgreSQL database already holds rows')]
        bool $force = false,
    ): ExitCode {
        $this->console->writeln("Importing <em>{$path}</em> into PostgreSQL...");

        try {
            $copied = $this->importer->import($path, $force);
        } catch (\RuntimeException $exception) {
            // The import is transactional: nothing was committed, and the
            // SQLite file was opened read-only, so it is still the rollback path.
            $this->console->error($exception->getMessage());

            return ExitCode::ERROR;
        }

        foreach ($copied as $table => $rows) {
            $this->console->keyValue($table, (string) $rows);
        }

        $this->console->success(sprintf(
            'Imported %d row(s) across %d table(s). Keep the SQLite file until you have verified the upgrade.',
            array_sum($copied),
            count($copied),
        ));

        return ExitCode::SUCCESS;
    }
}
