<?php

declare(strict_types=1);

use Tempest\DateTime\Duration;
use Tempest\Process\GenericProcessExecutor;
use Tempest\Process\PendingProcess;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

test('one hundred files are independently preserved by continuous serial and concurrent consumers', function (int $workers, int $columns): void {
    $artifacts = new ArchiveFixture();
    $parent = getenv('STASHD_PRESERVATION_EVIDENCE_DIR') ?: dirname($artifacts->path('report'));
    $directory = $parent . '/100-' . $workers . '-' . bin2hex(random_bytes(4));
    expect(mkdir($directory, 0o700))->toBeTrue();
    try {
        $result = new GenericProcessExecutor()->run(new PendingProcess(
            command: [
                'python3',
                'tests/Support/Integration/filesystem_preservation_pty.py',
                PHP_BINARY,
                $directory,
                '100',
                (string) $workers,
                (string) $columns,
                'none',
                $directory . '/cli.pty',
            ],
            timeout: Duration::seconds(200),
            environment: [...getenv()],
        ));
        expect($result->exitCode)->toBe(0, $result->output . $result->errorOutput);
        $files = glob($directory . '/filesystem-preservation-*.json');
        expect($files)->toHaveCount(1);
        $report = json_decode(file_get_contents($files[0]), true, flags: JSON_THROW_ON_ERROR);
        expect($report['status'])->toBe('passed');
        expect($report['completed_jobs'])->toBe(100);
        expect($report['verified_items'])->toBe(100);
        expect($report['verified_assets'])->toBe(100);
        expect($report['completion_receipts'])->toHaveCount(100);
        expect(array_unique(array_column($report['completion_receipts'], 'source_id')))->toHaveCount(100);
        expect(array_unique(array_column($report['completion_receipts'], 'assignment_id')))->toHaveCount(100);
        expect($report['preservation_witnesses'])->toHaveCount(100);
        expect($report['max_observed_acquiring_workers'])->toBeGreaterThanOrEqual($workers === 1 ? 1 : 2)->toBeLessThanOrEqual($workers);
        foreach ($report['overlap_witness']['acquisitions'] as $active) {
            expect($report['worker_pids'])->toContain((int) substr($active['application_name'], strlen('stashd-filesystem-worker:')));
        }

        expect($report['phases']['discover']['worker_pids'])->toBe($report['phases']['preserve']['worker_pids']);
        expect($report['phases']['cleanup']['worker_pids'])->toBe($report['worker_pids']);
        expect($report['database_and_role_independently_absent'])->toBeTrue();
        expect($report['cleanup'])->toBe('completed');
        expect(is_dir($report['fixture_root']))->toBeFalse();
        foreach ($report['worker_pids'] as $pid) {
            expect(is_dir('/proc/' . $pid))->toBeFalse();
        }
    } finally {
        $artifacts->dispose();
    }
})->with(['serial / ordinary' => [1, 80], 'concurrent / narrow' => [4, 40]]);
