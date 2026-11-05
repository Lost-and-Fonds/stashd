<?php

declare(strict_types=1);

use App\Development\FilesystemPreservationCommand;
use Tempest\Console\Console;
use Tempest\Core\Environment;

test('the preservation command refuses production before fixture allocation', function (): void {
    $console = $this->createMock(Console::class);
    $output = '';
    $console
        ->method('writeRaw')
        ->willReturnCallback(static function (string $message) use (&$output, $console): Console {
            $output .= $message;

            return $console;
        });
    expect(new FilesystemPreservationCommand($console, Environment::PRODUCTION)('100', '4', json: true))->toBe(1);
    expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['failure_type'])->toBe(InvalidArgumentException::class);
});

test('invalid preservation workloads and report directories are rejected before running', function (int $count, int $workers): void {
    expect(fn () => new Tests\Support\Integration\FilesystemPreservationExperiment($count, $workers, getcwd()))->toThrow(InvalidArgumentException::class);
})->with([[0, 1], [10_001, 1], [1, 0], [1, 21]]);

test('the preservation experiment requires an existing report directory', function (): void {
    expect(fn () => new Tests\Support\Integration\FilesystemPreservationExperiment(1, 1, '/this-report-directory-does-not-exist'))->toThrow(InvalidArgumentException::class);
});
