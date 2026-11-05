<?php

declare(strict_types=1);

use Tests\Support\Integration\FilesystemPreservationExperiment;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

test('real filesystem preservation completes and tears down only its owned fixture', function (int $workers): void {
    $artifacts = new ArchiveFixture();
    try {
        $directory = getenv('STASHD_PRESERVATION_EVIDENCE_DIR') ?: dirname($artifacts->path('report'));
        $report = new FilesystemPreservationExperiment(8, $workers, $directory)->run();
        expect($report['status'])->toBe('passed', json_encode($report));
        expect($report['admitted'])->toBe(8);
        expect($report['completed_jobs'])->toBe(8);
        expect($report['verified_items'])->toBe(8);
        expect($report['verified_assets'])->toBe(8);
        expect($report['cleanup'])->toBe('completed');
        expect(is_dir($report['fixture_root']))->toBeFalse();
        foreach ($report['worker_pids'] as $pid) {
            expect(is_dir('/proc/' . $pid))->toBeFalse();
        }

        expect($report['max_observed_acquiring_workers'])->toBeGreaterThanOrEqual($workers === 1 ? 1 : 2);
        foreach ($report['overlap_witness']['acquisitions'] as $worker) {
            expect($worker['application_name'])->toStartWith('stashd-filesystem-worker:');
            expect($report['worker_pids'])->toContain((int) substr($worker['application_name'], strlen('stashd-filesystem-worker:')));
        }
    } finally {
        $artifacts->dispose();
    }
})->with(['serial' => [1], 'concurrent' => [4]]);

test('partial preservation remains a failure with retained evidence after interruption or worker death', function (bool $cancel): void {
    $artifacts = new ArchiveFixture();
    $injected = false;
    try {
        $directory = getenv('STASHD_PRESERVATION_EVIDENCE_DIR') ?: dirname($artifacts->path('report'));
        $report = new FilesystemPreservationExperiment(20, 4, $directory, observer: static function (array $state) use ($cancel, &$injected): void {
            if (! $injected && $state['phase'] === 'preserve' && $state['completed_jobs'] >= 2 && $state['completed_jobs'] < 20) {
                $injected = true;
                posix_kill($cancel ? getmypid() : $state['worker_pids'][0], $cancel ? SIGINT : SIGKILL);
            }
        })->run();
        expect($injected)->toBeTrue();
        expect($report['status'])->toBe('failed');
        expect($report['completed_jobs'])->toBeGreaterThanOrEqual(2)->toBeLessThan(20);
        expect($report['verified_items'])->toBe(0);
        expect($report['cancelled'])->toBe($cancel);
        expect($report['cleanup'])->toBe('completed');
        expect(is_dir($report['fixture_root']))->toBeFalse();
        foreach ($report['worker_pids'] as $pid) {
            expect(is_dir('/proc/' . $pid))->toBeFalse();
        }

        $saved = file_get_contents($directory . '/' . $report['report_file']);
        expect(json_decode($saved, true, flags: JSON_THROW_ON_ERROR))->toBe($report);
        expect($saved)->not->toContain('password', 'dsn', 'SIGNING_KEY', "\e[");
    } finally {
        $artifacts->dispose();
    }
})->with(['cancelled' => [true], 'dead worker' => [false]]);

test('a real acquisition failure retains partial outcomes without claiming verification', function (): void {
    $artifacts = new ArchiveFixture();
    $injected = false;
    try {
        $directory = getenv('STASHD_PRESERVATION_EVIDENCE_DIR') ?: dirname($artifacts->path('report'));
        $report = new FilesystemPreservationExperiment(20, 4, $directory, observer: static function (array $state) use (&$injected): void {
            if (! $injected && $state['phase'] === 'preserve' && $state['completed_jobs'] >= 2) {
                $injected = true;
                // Fault only the generated source, never the Vault or an operator path.
                foreach (glob($state['fixture_root'] . '/inputs/*/*/*.txt') as $file) {
                    unlink($file);
                }
            }
        })->run();
        expect($injected)->toBeTrue();
        expect($report['status'])->toBe('failed');
        expect($report['failed_attempts'])->toBeGreaterThan(0);
        expect($report['verified_items'])->toBe(0);
        expect($report['cleanup'])->toBe('completed');
        expect(array_column($report['attempt_history'], 'outcome'))->toContain('failed');
    } finally {
        $artifacts->dispose();
    }
});
