<?php

declare(strict_types=1);

namespace App\Development;

use Closure;
use InvalidArgumentException;
use Laravel\Prompts\Support\Logger;
use Tempest\Console\Console;
use Tempest\Console\ConsoleCommand;
use Tempest\Core\Environment;
use Tests\Support\Integration\FilesystemPreservationExperiment;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\number;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Tempest\env;

/**
 * Present the real preservation-only experiment without changing the approved nine-stage reference.
 * Fixture administration is a development dependency; production environments are refused.
 */
final readonly class FilesystemPreservationCommand
{
    /**
     * Retain Tempest's environment and console boundary without touching the application database.
     */
    public function __construct(
        private Console $console,
        private Environment $environment,
    ) {}

    /**
     * Configure and run the same preservation checks in interactive, unattended and JSON modes.
     * Stages 4–8 are explicitly not run until the next review gate; they are never marked successful.
     */
    #[ConsoleCommand(name: 'stress:filesystem-preserve', description: 'Run disposable filesystem discovery and preservation (development only).')]
    public function __invoke(?string $count = null, ?string $workers = null, ?string $reportDir = null, bool $json = false): int
    {
        try {
            // @mago-expect analysis:non-existent-class-like
            if (
                ! in_array($this->environment, [Environment::LOCAL, Environment::TESTING, Environment::CONTINUOUS_INTEGRATION], true)
                || ! class_exists(FilesystemPreservationExperiment::class)
            ) {
                throw new InvalidArgumentException('Filesystem stress requires a development environment and Composer dev dependencies.');
            }

            if (! is_string(env('SIGNING_KEY')) || env('SIGNING_KEY') === '') {
                throw new InvalidArgumentException('Configure a stable SIGNING_KEY before starting workers.');
            }

            $interactive = ! $json && $this->console->supportsPrompting() && stream_isatty(STDIN) && stream_isatty(STDOUT);
            if (! $interactive && ($count === null || $workers === null)) {
                throw new InvalidArgumentException('Unattended runs require --count and --workers.');
            }

            $reportDir ??= getcwd() ?: '.';
            if ($interactive) {
                intro('Stashd · Microtext Stress Test');
                note('Preservation slice: real discovery, acquisition and independent verification. Publication stages 04–08 are not run.');
                $count = (string) number(label: 'File count', default: $count ?? '100', min: 1, max: 10_000, required: true, hint: 'Number of unique microtext files.');
                $workers = (string) number(
                    label: 'Concurrent workers',
                    default: $workers ?? '1',
                    min: 1,
                    max: 20,
                    required: true,
                    hint: '1 for serial execution; more for parallelism.',
                );
                $reportDir = text(label: 'Report directory', default: $reportDir, required: true, hint: 'Existing directory; the report survives teardown.');
                table(headers: ['Configuration', 'Value'], rows: [
                    ['Files',    $count],
                    ['Workers',  $workers],
                    ['Database', 'Disposable'],
                    ['Storage',  'Disposable'],
                    ['Mode',     'Preservation only'],
                ]);
                if (! confirm(label: 'Begin filesystem preservation?', default: true, hint: 'Only its generated database, source and Vault are removed.')) {
                    outro('Cancelled. Nothing was changed.');

                    return 0;
                }
            }

            if (! ctype_digit($count) || ! ctype_digit($workers)) {
                throw new InvalidArgumentException('File and worker counts must be positive integers.');
            }

            $labels = [
                'prepare' => ['01 / 09  Prepare source files', "01 / 09  Prepared {$count} source files"],
                'discover' => ['02 / 09  Discover files', "02 / 09  Discovered {$count} files"],
                'preserve' => ['03 / 09  Preserve files', "03 / 09  Preserved {$count} files · independently verified"],
                'cleanup' => ['09 / 09  Clean up owned fixture', '09 / 09  Owned fixture cleaned'],
            ];
            $presentation = static function (string $stage, Closure $work) use ($interactive, $labels): void {
                if (! $interactive) {
                    fwrite(STDERR, $labels[$stage][0] . "\n");
                    $work(static function (): void {});

                    return;
                }

                new FilesystemStressTask(label: $labels[$stage][0], keepSummary: true, limit: 4)->run(static function (Logger $log) use ($work, $labels, $stage): void {
                    $lastActivity = null;
                    $lastCompleted = null;
                    $work(static function (int $completed, int $total, string $activity) use ($log, &$lastActivity, &$lastCompleted): void {
                        if ($lastActivity === $activity && $lastCompleted === $completed) {
                            return;
                        }

                        $narrow = \Laravel\Prompts\Prompt::terminal()->cols() < 70;
                        $filled = max(0, min(18, (int) ((18 * $completed) / max(1, $total))));
                        $bar = str_repeat('━', $filled) . str_repeat('─', max(0, 18 - $filled));
                        $log->subLabel($narrow ? "{$completed}/{$total} {$activity}" : "{$activity}  {$bar}  {$completed}/{$total}");
                        if ($activity !== $lastActivity || $completed === $total) {
                            // Let the stock renderer display real subphase boundaries before the next reset.
                            usleep(200_000);
                        }

                        $lastActivity = $activity;
                        $lastCompleted = $completed;
                    });
                    $log->label($labels[$stage][1]);
                });
            };
            // Tests are deliberately absent from application analysis and production installations.
            // @mago-expect analysis:non-existent-class
            // @mago-expect analysis:impossible-assignment
            $experiment = new FilesystemPreservationExperiment((int) $count, (int) $workers, $reportDir, $presentation);
            /** @var array{status: string, completed_jobs: int, verified_items: int, verified_assets: int, max_observed_acquiring_workers: int, cleanup: string, elapsed_seconds: float, report_file: string, ...} $report */
            $report = $experiment->run();
            if ($json) {
                $this->console->writeRaw(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
            } else {
                table(headers: ['Result', 'Value'], rows: [
                    ['Status', strtoupper($report['status'])],
                    ['Jobs', (string) $report['completed_jobs']],
                    ['Verified I/A', $report['verified_items'] . ' / ' . $report['verified_assets']],
                    ['Acquiring', (string) $report['max_observed_acquiring_workers']],
                    ['Cleanup', $report['cleanup']],
                    ['Elapsed', sprintf('%.2fs', $report['elapsed_seconds'])],
                    ['04–08', 'Not run'],
                ]);
                note(wordwrap('Report: ' . $reportDir . '/' . $report['report_file'], max(20, \Laravel\Prompts\Prompt::terminal()->cols() - 6), "\n", true));
            }

            return $report['status'] === 'passed' ? 0 : 1;
        } catch (Throwable $error) {
            $failure = ['format' => 'stashd-filesystem-preservation-1', 'status' => 'failed', 'failure_type' => $error::class];
            if ($json) {
                $this->console->writeRaw(json_encode($failure, JSON_THROW_ON_ERROR) . "\n");
            }

            fwrite(STDERR, $error instanceof InvalidArgumentException ? $error->getMessage() . "\n" : 'Preservation experiment failed: ' . $error::class . "\n");

            return 1;
        }
    }
}
