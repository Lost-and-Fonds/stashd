<?php

declare(strict_types=1);

namespace App\Development;

use Closure;
use Laravel\Prompts\Support\Logger;
use RuntimeException;
use Tempest\Console\ConsoleCommand;
use Throwable;

use function Laravel\Prompts\{
    confirm,
    error,
    info,
    intro,
    note,
    number,
    outro,
    select,
    table,
    task,
    text
};

/**
 * Filesystem round-trip UX prototype.
 *
 * All operations, workers and results are simulated.
 * No database, Vault, files or reports are modified.
 */
final readonly class FilesystemStressPreviewCommand
{
    #[ConsoleCommand(
        name: 'stress:filesystem-preview',
        description: 'Preview the filesystem stress-test experience.',
    )]
    public function __invoke(
        int $count = 100,
        int $workers = 1,
        ?string $reportDir = null,
    ): int {
        $reportDir ??= getcwd() ?: '.';

        // ==============================================
        // WELCOME
        // ==============================================

        intro('Stashd · Microtext Stress Test');

        note(
            'Test a complete filesystem preservation round trip, '
            . 'from discovery through publication and verification.'
        );

        info('DESIGN PREVIEW — All operations are simulated.');

        // ==============================================
        // CONFIGURATION
        // ==============================================

        $count = (int) number(
            label: 'File count',
            default: (string) $count,
            min: 1,
            max: 10_000,
            required: true,
            hint: 'Number of unique microtext files.',
        );

        $workers = (int) number(
            label: 'Concurrent workers',
            default: (string) $workers,
            min: 1,
            max: 20,
            required: true,
            hint: '1 for serial execution; more for parallelism.',
        );

        $reportDir = text(
            label: 'Report directory',
            default: $reportDir,
            required: true,
            hint: 'Preview only — no report will be written.',
        );

        $pace = select(
            label: 'Simulation speed',
            options: [
                'fast' => 'Fast',
                'normal' => 'Normal',
                'slow' => 'Slow',
            ],
            default: 'normal',
        );

        $scenario = select(
            label: 'Simulated outcome',
            options: [
                'pass' => 'All checks pass',
                'worker' => 'Worker failure',
                'corruption' => 'Corruption goes undetected',
                'cleanup' => 'Cleanup failure',
            ],
            default: 'pass',
        );

        $speed = match ($pace) {
            'fast' => 0.3,
            'slow' => 3.0,
            default => 1.0,
        };

        // ==============================================
        // REVIEW
        // ==============================================

        table(
            headers: ['Configuration', 'Value'],
            rows: [
                ['Files', number_format($count)],
                ['Workers', (string) $workers],
                ['Execution', $workers === 1 ? 'Serial' : 'Parallel'],
                ['Report directory', $reportDir],
                ['Database', 'Disposable'],
                ['Storage', 'Disposable'],
                ['Mode', 'Simulation'],
            ],
        );

        if (! confirm(
            label: 'Begin filesystem round trip?',
            default: true,
            hint: 'This preview performs no real operations.',
        )) {
            outro('Cancelled. Nothing was changed.');

            return 0;
        }

        // ==============================================
        // EXECUTION
        // ==============================================

        $started = microtime(true);
        $timings = [];
        $failure = null;
        $cleaned = false;

        try {
            // ------------------------------------------
            // 01 — PREPARE SOURCE
            // ------------------------------------------

            $timings['Prepare'] = $this->phase(
                '01 / 09  Prepare source files',
                function (Logger $log) use ($count, $speed): void {
                    $this->simulateFiles(
                        log: $log,
                        activity: 'Generating microtext',
                        count: $count,
                        workers: 1,
                        microseconds: 4_000,
                        speed: $speed,
                    );

                    $this->simulateFiles(
                        log: $log,
                        activity: 'Checking source bytes',
                        count: $count,
                        workers: 1,
                        microseconds: 3_000,
                        speed: $speed,
                    );

                    $log->label(
                        "01 / 09  Prepared {$count} source files"
                    );
                },
            );

            // ------------------------------------------
            // 02 — DISCOVERY
            // ------------------------------------------

            $timings['Discover'] = $this->phase(
                '02 / 09  Discover files',
                function (Logger $log) use ($count, $speed): void {
                    $this->simulateWait(
                        $log,
                        'Configuring Filesystem input',
                        350_000,
                        $speed,
                    );

                    $this->simulateFiles(
                        log: $log,
                        activity: 'Discovering files',
                        count: $count,
                        workers: 1,
                        microseconds: 8_000,
                        speed: $speed,
                    );

                    $this->simulateWait(
                        $log,
                        'Checking discovery coverage',
                        250_000,
                        $speed,
                    );

                    $log->label(
                        "02 / 09  Discovered {$count} files"
                    );
                },
            );

            // ------------------------------------------
            // 03 — PRESERVATION
            // ------------------------------------------

            $timings['Preserve'] = $this->phase(
                '03 / 09  Preserve files',
                function (Logger $log) use (
                    $count,
                    $workers,
                    $speed,
                    $scenario,
                ): void {
                    $this->simulateWait(
                        $log,
                        'Starting preservation workers',
                        350_000,
                        $speed,
                    );

                    $this->simulateFiles(
                        log: $log,
                        activity: 'Preserving files',
                        count: $count,
                        workers: $workers,
                        microseconds: 25_000,
                        speed: $speed,
                        failAt: $scenario === 'worker'
                            ? (int) ceil($count * 0.65)
                            : null,
                    );

                    $this->simulateFiles(
                        log: $log,
                        activity: 'Verifying OCFL assets',
                        count: $count,
                        workers: 1,
                        microseconds: 6_000,
                        speed: $speed,
                    );

                    $log->label(
                        "03 / 09  Preserved {$count} assets"
                    );
                },
            );

            // ------------------------------------------
            // 04 — UNCHANGED RESCAN
            // ------------------------------------------

            $timings['Rescan'] = $this->phase(
                '04 / 09  Verify unchanged input',
                function (Logger $log) use ($count, $speed): void {
                    $this->simulateFiles(
                        log: $log,
                        activity: 'Rediscovering files',
                        count: $count,
                        workers: 1,
                        microseconds: 7_000,
                        speed: $speed,
                    );

                    $this->simulateFiles(
                        log: $log,
                        activity: 'Checking archival versions',
                        count: $count,
                        workers: 1,
                        microseconds: 4_000,
                        speed: $speed,
                    );

                    $log->label(
                        '04 / 09  No new archival revisions'
                    );
                },
            );

            // ------------------------------------------
            // 05 — REMOVE SOURCE
            // ------------------------------------------

            $timings['Remove source'] = $this->phase(
                '05 / 09  Remove original source',
                function (Logger $log) use ($speed): void {
                    $this->simulateWait(
                        $log,
                        'Removing disposable source files',
                        550_000,
                        $speed,
                    );

                    $this->simulateWait(
                        $log,
                        'Confirming source is unavailable',
                        250_000,
                        $speed,
                    );

                    $log->label(
                        '05 / 09  Original source removed'
                    );
                },
            );

            // ------------------------------------------
            // 06 — PUBLICATION
            // ------------------------------------------

            $timings['Publish'] = $this->phase(
                '06 / 09  Publish from the Vault',
                function (Logger $log) use (
                    $count,
                    $workers,
                    $speed,
                ): void {
                    $this->simulateWait(
                        $log,
                        'Freezing Broadcast selection',
                        350_000,
                        $speed,
                    );

                    $this->simulateFiles(
                        log: $log,
                        activity: 'Publishing files',
                        count: $count,
                        workers: $workers,
                        microseconds: 12_000,
                        speed: $speed,
                    );

                    $this->simulateFiles(
                        log: $log,
                        activity: 'Verifying output bytes',
                        count: $count,
                        workers: 1,
                        microseconds: 5_000,
                        speed: $speed,
                    );

                    $log->label(
                        "06 / 09  Published {$count} verified files"
                    );
                },
            );

            // ------------------------------------------
            // 07 — REPEAT PUBLICATION
            // ------------------------------------------

            $timings['Republish'] = $this->phase(
                '07 / 09  Verify repeat publication',
                function (Logger $log) use (
                    $count,
                    $workers,
                    $speed,
                ): void {
                    $this->simulateFiles(
                        log: $log,
                        activity: 'Republishing files',
                        count: $count,
                        workers: $workers,
                        microseconds: 9_000,
                        speed: $speed,
                    );

                    $this->simulateFiles(
                        log: $log,
                        activity: 'Checking bytes and inodes',
                        count: $count,
                        workers: 1,
                        microseconds: 5_000,
                        speed: $speed,
                    );

                    $log->label(
                        '07 / 09  Output unchanged after republish'
                    );
                },
            );

            // ------------------------------------------
            // 08 — CORRUPTION DETECTION
            // ------------------------------------------

            $timings['Corruption'] = $this->phase(
                '08 / 09  Test corruption detection',
                function (Logger $log) use (
                    $scenario,
                    $speed,
                ): void {
                    $this->simulateWait(
                        $log,
                        'Deliberately corrupting one output file',
                        400_000,
                        $speed,
                    );

                    $this->simulateWait(
                        $log,
                        'Running independent verifier',
                        650_000,
                        $speed,
                    );

                    if ($scenario === 'corruption') {
                        throw new RuntimeException(
                            'Verifier accepted corrupted output.'
                        );
                    }

                    $log->label(
                        '08 / 09  Corruption correctly detected'
                    );
                },
            );

        } catch (Throwable $exception) {
            $failure = $exception->getMessage();

            error("Experiment failed: {$failure}");

        } finally {
            // ------------------------------------------
            // 09 — CLEANUP (ALWAYS ATTEMPTED)
            // ------------------------------------------

            try {
                $timings['Cleanup'] = $this->phase(
                    '09 / 09  Clean up',
                    function (Logger $log) use (
                        $scenario,
                        $speed,
                    ): void {
                        $this->simulateWait(
                            $log,
                            'Stopping workers',
                            300_000,
                            $speed,
                        );

                        $this->simulateWait(
                            $log,
                            'Removing disposable database',
                            350_000,
                            $speed,
                        );

                        $this->simulateWait(
                            $log,
                            'Removing temporary storage',
                            350_000,
                            $speed,
                        );

                        if ($scenario === 'cleanup') {
                            throw new RuntimeException(
                                'Could not remove disposable environment.'
                            );
                        }

                        $log->label(
                            '09 / 09  Cleanup complete'
                        );
                    },
                );

                $cleaned = true;

            } catch (Throwable $exception) {
                $failure = $failure === null
                    ? $exception->getMessage()
                    : $failure . '; ' . $exception->getMessage();

                error("Cleanup failed: {$exception->getMessage()}");
            }
        }

        // ==============================================
        // RESULTS
        // ==============================================

        $elapsed = microtime(true) - $started;

        if ($failure !== null) {
            table(
                headers: ['Result', 'Value'],
                rows: [
                    ['Status', 'FAILED (simulated)'],
                    ['Files requested', number_format($count)],
                    ['Workers', (string) $workers],
                    ['Elapsed', sprintf('%.2f s', $elapsed)],
                    ['Cleanup', $cleaned ? 'Complete' : 'Not confirmed'],
                    ['Report', 'Not written'],
                ],
            );

            return 1;
        }

        $preserveSeconds = $timings['Preserve'] ?? 0.0;

        $throughput = $preserveSeconds > 0
            ? $count / $preserveSeconds
            : 0.0;

        table(
            headers: ['Result', 'Value'],
            rows: [
                ['Status', 'PASSED (simulated)'],
                ['Files discovered', number_format($count)],
                ['Items preserved', number_format($count)],
                ['Assets verified', number_format($count)],
                ['Files published', number_format($count)],
                ['Files republished', number_format($count)],
                ['New archival revisions', '0'],
                ['Corruption detection', 'Passed'],
                ['Cleanup', 'Complete'],
                ['Workers', (string) $workers],
                ['Preservation phase rate',
                    sprintf('%.1f files/s', $throughput)],
                ['Total elapsed',
                    sprintf('%.2f s', $elapsed)],
            ],
        );

        note(
            "Report destination: {$reportDir} (preview only)"
        );

        outro('Filesystem round trip passed!');

        return 0;
    }

    /**
     * Run one collapsible task.
     *
     * With keepSummary=true and no persistent status
     * messages, Laravel Prompts retains one completion
     * line and erases the live output.
     */
    private function phase(
        string $label,
        Closure $callback,
    ): float {
        $started = microtime(true);

        task(
            label: $label,
            callback: $callback,
            keepSummary: true,
            limit: 4,
        );

        return microtime(true) - $started;
    }

    /**
     * Simulate a countable file operation.
     *
     * Each iteration represents one completed file.
     * Updates are rendered inside the task's sub-label,
     * rather than using a nested Progress component.
     *
     * Workers affect simulated timing, not concurrency.
     */
    private function simulateFiles(
        Logger $log,
        string $activity,
        int $count,
        int $workers,
        int $microseconds,
        float $speed,
        ?int $failAt = null,
    ): void {
        $started = microtime(true);
        $lastUpdate = 0.0;

        // Bound demo duration for large workloads.
        $perFile = min(
            $microseconds,
            max(500, (int) (4_000_000 / $count)),
        );

        $delay = max(
            150,
            (int) round(
                $perFile * $speed / max(1, $workers)
            ),
        );

        for ($file = 1; $file <= $count; $file++) {
            // Simulated completion of one real file.
            usleep($delay);

            $now = microtime(true);
            $elapsed = max(0.001, $now - $started);

            // Update every file for small workloads.
            // Throttle IPC updates for very large ones.
            $shouldUpdate = $count <= 200
                || $file === 1
                || $file === $count
                || ($now - $lastUpdate) >= 0.08;

            if ($shouldUpdate) {
                $percent = $file / $count;
                $width = 18;

                $filled = (int) round(
                    $percent * $width
                );

                $bar = str_repeat('━', $filled)
                    . str_repeat('─', $width - $filled);

                $rate = $file / $elapsed;

                $log->subLabel(sprintf(
                    '%s  %s  %d/%d  %.0f/s  %dw',
                    $activity,
                    $bar,
                    $file,
                    $count,
                    $rate,
                    $workers,
                ));

                $lastUpdate = $now;
            }

            if ($failAt !== null && $file >= $failAt) {
                throw new RuntimeException(sprintf(
                    'Simulated worker failure at file %d/%d.',
                    $file,
                    $count,
                ));
            }
        }
    }

    /**
     * Simulate an indeterminate operation.
     *
     * The task spinner continues while the sub-label
     * describes the current operation.
     */
    private function simulateWait(
        Logger $log,
        string $activity,
        int $microseconds,
        float $speed,
    ): void {
        $log->subLabel($activity . '...');

        usleep(
            max(1, (int) round($microseconds * $speed))
        );
    }
}
