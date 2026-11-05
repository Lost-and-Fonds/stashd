<?php

declare(strict_types=1);

namespace App\Development;

use InvalidArgumentException;
use Tempest\Console\Console;
use Tempest\Console\ConsoleCommand;
use Tempest\Core\Environment;
use Tests\Support\Integration\FilesystemStressRunner;
use Throwable;

use function Tempest\env;

/**
 * Expose the disposable 100-Item acceptance experiment only in development and testing.
 * Fixture administration remains in Composer's development-only test support.
 */
final readonly class FilesystemStressCommand
{
    /**
     * Bind console input and the environment gate without opening a database or Vault.
     */
    public function __construct(
        private Console $console,
        private Environment $environment,
    ) {}

    /**
     * Run serially after explicit unattended arguments or interactive confirmation.
     * JSON stdout contains only the final report; progress and diagnostics use stderr.
     */
    #[ConsoleCommand(name: 'stress:filesystem', description: 'Run the isolated, serial 100-Item Filesystem acceptance experiment (development only).')]
    public function __invoke(?string $count = null, ?string $reportDir = null, bool $json = false): int
    {
        $presenter = null;
        try {
            // Mago excludes tests; this optional dependency is deliberately Composer dev-only.
            // @mago-expect analysis:non-existent-class-like
            if (
                ! in_array($this->environment, [Environment::LOCAL, Environment::TESTING, Environment::CONTINUOUS_INTEGRATION], true)
                || ! class_exists(FilesystemStressRunner::class)
            ) {
                throw new InvalidArgumentException('Filesystem stress requires a development/testing environment and Composer dev dependencies.');
            }

            if ($count !== null && $count !== '100') {
                throw new InvalidArgumentException('Only --count=100 is supported.');
            }

            $interactive = ! $json && $this->console->supportsPrompting() && stream_isatty(STDIN) && stream_isatty(STDERR);
            if ($count === null && ! $interactive) {
                throw new InvalidArgumentException('Unattended runs require --count=100 --no-interaction.');
            }

            if (! is_string(env('SIGNING_KEY')) || env('SIGNING_KEY') === '') {
                throw new InvalidArgumentException('Configure one stable SIGNING_KEY before running stress; it must remain unchanged across workers and retries.');
            }

            if ($interactive && $count === null) {
                $this->console->writeln('Serial run; only its new disposable database, Vault and source are removed.');
                if (! $this->console->confirm('Run the 100-Item stress test?')) {
                    return 1;
                }

                if ($reportDir === null) {
                    $answer = $this->console->ask('Existing report directory', default: getcwd());
                    if (! is_string($answer)) {
                        throw new InvalidArgumentException('A report directory is required.');
                    }

                    $reportDir = $answer;
                }
            }

            $reportDir ??= getenv('STASHD_FILESYSTEM_STRESS_REPORT_DIR') ?: getcwd();
            $presenter = new FilesystemStressPresenter($this->console, $interactive);
            /** @var array{status: string, failure_type?: ?string, ...} $report */
            // @mago-expect analysis:non-existent-method
            $report = FilesystemStressRunner::run(100, $presenter->update(...), $reportDir, $presenter->diagnostic(...));
            $presenter->finish($report, $json);

            return $report['status'] === 'passed' ? 0 : 1;
        } catch (Throwable $error) {
            fwrite(STDERR, $error instanceof InvalidArgumentException ? $error->getMessage() . "\n" : 'Stress failed: ' . $error::class . "\n");
            $report = ['format' => 'stashd-filesystem-stress-1', 'status' => 'failed', 'failure_type' => $error::class];
            ($presenter ?? new FilesystemStressPresenter($this->console, false))->finish($report, $json);

            return 1;
        }
    }
}
