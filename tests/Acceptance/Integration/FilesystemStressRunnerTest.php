<?php

declare(strict_types=1);

use App\Development\DTO\FilesystemStressProgress;
use Tests\Support\Integration\FilesystemStressRunner;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

test('a failed stress run saves a structured report and cleans its owned fixture', function (bool $interrupt): void {
    $reports = new ArchiveFixture();
    $directory = dirname($reports->path('report'));
    try {
        $report = FilesystemStressRunner::run(
            100,
            static function (FilesystemStressProgress $progress) use ($interrupt): void {
                if ($progress->phase === 'discovery') {
                    if ($interrupt) {
                        posix_kill(getmypid(), SIGINT);
                    } else {
                        throw new RuntimeException('Deliberate observation failure.');
                    }
                }
            },
            $directory,
        );
        expect($report['status'])->toBe('failed');
        expect($report['interrupted'])->toBe($interrupt);
        expect($report['cleanup'])->toBe('completed');
        $saved = file_get_contents($directory . '/' . $report['report_file']);
        expect(json_decode($saved, true, flags: JSON_THROW_ON_ERROR))->toBe($report);
        expect($saved)->not->toContain('password', 'dsn', 'SIGNING_KEY', "\e[");
        expect(array_column($report['phases'], 'phase'))->not->toContain('negative_control_rejected');
        expect(glob($directory . '/*.partial.json'))->toBe([]);
    } finally {
        $reports->dispose();
    }
})->with(['interruption' => [true], 'observer error' => [false]]);
