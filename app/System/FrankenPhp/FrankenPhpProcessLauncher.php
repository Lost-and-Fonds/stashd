<?php

declare(strict_types=1);

namespace App\System\FrankenPhp;

use Tempest\Console\ExitCode;
use Tempest\Process\ProcessExecutor;

final readonly class FrankenPhpProcessLauncher
{
    public function __construct(
        private ProcessExecutor $processes,
    ) {}

    public function serve(): ExitCode
    {
        $root = dirname(__DIR__, 3);

        $this->processes->run("frankenphp run --config {$root}/docker/Caddyfile");

        return ExitCode::SUCCESS;
    }
}
