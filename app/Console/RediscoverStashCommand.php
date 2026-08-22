<?php

declare(strict_types=1);

namespace App\Console;

use App\Stashes\RediscoverStash;
use App\System\Boot\SqliteConfigurator;
use Tempest\Console\ConsoleArgument;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\ExitCode;
use Tempest\Console\HasConsole;
use Tempest\Database\Config\DatabaseConfig;

final readonly class RediscoverStashCommand
{
    use HasConsole;

    public function __construct(
        private RediscoverStash $rediscover,
        private SqliteConfigurator $sqlite,
        private DatabaseConfig $databaseConfig,
    ) {}

    #[ConsoleCommand(
        name: 'stashd:rediscover',
        description: 'Fill missing media metadata from a stash’s current discovery results without overwriting saved values.',
    )]
    public function __invoke(
        #[ConsoleArgument(description: 'Stash ID to rediscover')]
        string $stashId,
    ): ExitCode {
        $this->sqlite->configure($this->databaseConfig);
        $result = $this->rediscover->execute($stashId);

        $this->console->success("Rediscovered {$result['inputs']} input(s): {$result['fields']} missing field(s) filled across {$result['updated']} media item(s).");

        return ExitCode::SUCCESS;
    }
}
