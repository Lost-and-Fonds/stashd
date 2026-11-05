<?php

declare(strict_types=1);

use Tempest\DateTime\Duration;
use Tempest\Process\GenericProcessExecutor;
use Tempest\Process\PendingProcess;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

test('the preservation CLI runs real configuration and collapsing tasks in narrow and ordinary terminals', function (string $action, int $columns): void {
    $artifacts = new ArchiveFixture();
    $directory = dirname($artifacts->path('report'));
    if ($parent = getenv('STASHD_PRESERVATION_EVIDENCE_DIR')) {
        $directory = $parent . '/cli-' . $action . '-' . $columns . '-' . bin2hex(random_bytes(4));
        mkdir($directory, 0o700);
    }

    try {
        $result = new GenericProcessExecutor()->run(new PendingProcess(
            command: [
                'python3',
                'tests/Support/Integration/filesystem_preservation_pty.py',
                PHP_BINARY,
                $directory,
                $action === 'none' ? '8' : '20',
                '4',
                (string) $columns,
                $action,
                $directory . '/cli.pty',
            ],
            timeout: Duration::seconds(200),
            environment: [...getenv()],
        ));
        $reports = glob($directory . '/filesystem-preservation-*.json');
        expect($result->exitCode)->toBe(0, $result->output . $result->errorOutput . ($reports === [] ? '' : file_get_contents($reports[0])));
        expect($reports)->toHaveCount(1);
        $report = json_decode(file_get_contents($reports[0]), true, flags: JSON_THROW_ON_ERROR);
        expect($report['status'])->toBe($action === 'none' ? 'passed' : 'failed');
        expect($report['cancelled'])->toBe($action !== 'none');
        expect($report['database_and_role_independently_absent'])->toBeTrue();
        expect($report['cleanup'])->toBe('completed');
        foreach ($report['worker_pids'] as $pid) {
            expect(is_dir('/proc/' . $pid))->toBeFalse();
        }
    } finally {
        $artifacts->dispose();
    }
})->with(['narrow' => ['none', 40], 'ordinary' => ['none', 80], 'terminal Ctrl+C' => ['ctrl-c', 40], 'group SIGTERM' => ['sigterm', 80]]);

test('unattended preservation JSON is undecorated and matches the retained report', function (): void {
    $artifacts = new ArchiveFixture();
    $directory = dirname($artifacts->path('report'));
    if ($parent = getenv('STASHD_PRESERVATION_EVIDENCE_DIR')) {
        $directory = $parent . '/json-' . bin2hex(random_bytes(4));
        mkdir($directory, 0o700);
    }

    try {
        $result = new GenericProcessExecutor()->run(new PendingProcess(
            command: [
                PHP_BINARY,
                '-d',
                'lerd.devtools_flag=/disabled-for-preservation-json',
                'tempest',
                'stress:filesystem-preserve',
                '--count=4',
                '--workers=1',
                '--report-dir=' . $directory,
                '--no-interaction',
                '--json',
            ],
            timeout: Duration::seconds(60),
            environment: [...getenv()],
        ));
        expect($result->exitCode)->toBe(0, $result->output . $result->errorOutput);
        $report = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
        expect($report)->toBe(json_decode(file_get_contents($directory . '/' . $report['report_file']), true, flags: JSON_THROW_ON_ERROR));
        expect($report['status'])->toBe('passed');
        expect($report['verified_items'])->toBe(4);
        file_put_contents($directory . '/stdout.json', $result->output);
        expect($result->output)->not->toContain("\e[", 'password', 'dsn', 'SIGNING_KEY');
    } finally {
        $artifacts->dispose();
    }
});
