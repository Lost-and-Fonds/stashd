<?php

declare(strict_types=1);

use Tests\Support\Integration\FilesystemStressRunner;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

require_once dirname(__DIR__, 2) . '/Support/Integration/configuration-cli.php';

/**
 * Exercise the CLI's shared orchestration with the original independent acceptance checks.
 * Larger workloads remain opt-in here, outside ordinary phpunit.xml test discovery.
 */
test('measured directory workflow preserves and republishes every independent Item from the Vault', function (int $count): void {
    if ($count === 100) {
        $reports = new ArchiveFixture();
        try {
            $directory = getenv('STASHD_FILESYSTEM_STRESS_REPORT_DIR') ?: getenv('STASHD_PR5_REPORT_DIR') ?: dirname($reports->path('report'));
            $process = proc_open(
                [PHP_BINARY, 'tempest', 'stress:filesystem', '--count=100', '--no-interaction', '--json', '--report-dir=' . $directory],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                dirname(__DIR__, 3),
                [...getenv(), 'ENVIRONMENT' => 'testing'],
            );
            $result = finish_configuration_cli(['process' => $process, 'pipes' => $pipes]);
            $report = configuration_result($result);
            expect($result['errors'])->not->toContain('Deprecated:', 'devtools-collector.php');
            foreach (['verify_preservation', 'verify_unchanged_preservation', 'verify_first_publication', 'verify_repeat_publication'] as $phase) {
                expect($result['errors'])->toMatch('/^' . $phase . ' \|[^\n]*\| phase 0\/100 /m');
            }

            foreach (['repeat_broadcast', 'corruption_negative_control', 'cleanup'] as $phase) {
                expect($result['errors'])->toMatch('/^' . $phase . ' \|[^\n]*verified 100[^\n]*\| phase 0\/1 /m');
            }

            $saved = file_get_contents($directory . '/' . $report['report_file']);
            expect(json_decode($saved, true, flags: JSON_THROW_ON_ERROR))->toBe($report);
            expect($saved)->not->toContain('password', 'dsn', 'SIGNING_KEY', "\e[");
            expect(glob($directory . '/*.partial.json'))->toBe([]);
        } finally {
            $reports->dispose();
        }
    } else {
        $report = FilesystemStressRunner::run($count);
    }

    expect($report['status'])->toBe('passed', json_encode($report, JSON_THROW_ON_ERROR));
    expect($report['format'])->toBe('stashd-filesystem-stress-1');
    expect($report['cleanup'])->toBe('completed');
    $last = $report['phases'][array_key_last($report['phases'])];
    expect($last['persisted_counts']['operation_results'])->toBe((2 * $count) + 4);
    expect($last['persisted_counts']['broadcast_reports'])->toBe(2 * $count);
    expect($last['attempt_outcomes'])->toBe(['completed' => (2 * $count) + 4]);
    expect(array_column($report['phases'], 'phase'))->toContain('negative_control_rejected');
    file_put_contents('php://stderr', 'FILESYSTEM_STRESS_METRICS ' . json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
})->with([
    '100 Items' => [100],
    '1,000 Items' => [1_000],
    'pinned 10,000 Items' => [10_000],
]);
