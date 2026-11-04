<?php

declare(strict_types=1);

use App\Development\DTO\FilesystemStressProgress;
use App\Development\FilesystemStressCommand;
use App\Development\FilesystemStressPresenter;
use PHPUnit\Framework\ExpectationFailedException;
use Tempest\Console\Console;
use Tempest\Core\Environment;
use Tests\Support\Integration\FilesystemStressRunner;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

require_once dirname(__DIR__) . '/Support/Integration/configuration-cli.php';

test('stress CLI rejects unsupported counts before creating fixtures', function (?string $count): void {
    $written = '';
    $console = $this->createStub(Console::class);
    $console
        ->method('writeRaw')
        ->willReturnCallback(function (string $text) use (&$written, $console): Console {
            $written .= $text;

            return $console;
        });
    $command = new FilesystemStressCommand($console, Environment::TESTING);
    expect($command($count, json: true))->toBe(1);
    expect(json_decode($written, true, flags: JSON_THROW_ON_ERROR)['status'])->toBe('failed');
    expect($written)->not->toContain("\e[");
})->with([null, '', '1', '99', '1000', '-100', '100.0', '100junk']);

test('stress CLI refuses production and staging even with valid unattended arguments', function (Environment $environment): void {
    $console = $this->createStub(Console::class);
    $console->method('writeRaw')->willReturn($console);
    expect(new FilesystemStressCommand($console, $environment)('100', json: true))->toBe(1);
})->with([Environment::PRODUCTION, Environment::STAGING]);

test('stress runner validates workload and report location without creating fixtures', function (): void {
    expect(fn () => FilesystemStressRunner::run(1))->toThrow(InvalidArgumentException::class);
    expect(fn () => FilesystemStressRunner::run(100, reportDirectory: '/this-report-directory-does-not-exist'))
        ->toThrow(InvalidArgumentException::class);
});

test('the legacy stress report directory remains a validated compatibility fallback', function (): void {
    $variables = ['STASHD_FILESYSTEM_STRESS_REPORT_DIR', 'STASHD_PR5_REPORT_DIR'];
    $previous = array_combine($variables, array_map(getenv(...), $variables));
    try {
        putenv('STASHD_FILESYSTEM_STRESS_REPORT_DIR');
        putenv('STASHD_PR5_REPORT_DIR=/this-legacy-report-directory-does-not-exist');
        expect(fn () => FilesystemStressRunner::run(100))->toThrow(InvalidArgumentException::class);
    } finally {
        foreach ($previous as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }
});

test('stress CLI refuses to generate a replacement signing key implicitly', function (): void {
    $key = getenv('SIGNING_KEY');
    try {
        putenv('SIGNING_KEY');
        $console = $this->createStub(Console::class);
        $console->method('writeRaw')->willReturn($console);
        expect(new FilesystemStressCommand($console, Environment::TESTING)('100', json: true))->toBe(1);
    } finally {
        putenv($key === false ? 'SIGNING_KEY' : 'SIGNING_KEY=' . $key);
    }
});

test('stress JSON presentation never manufactures success or contains terminal decoration', function (): void {
    $written = '';
    $console = $this->createStub(Console::class);
    $console
        ->method('writeRaw')
        ->willReturnCallback(function (string $text) use (&$written, $console): Console {
            $written .= $text;

            return $console;
        });
    $presenter = new FilesystemStressPresenter($console, false);
    $presenter->update(new FilesystemStressProgress('acquisition', 10, 100, 100, 100, 0, 0, 0, 0, 0, null));
    $report = ['format' => 'stashd-filesystem-stress-1', 'status' => 'failed', 'failure_type' => RuntimeException::class];
    $presenter->finish($report, true);
    expect(json_decode($written, true, flags: JSON_THROW_ON_ERROR))->toBe($report);
    expect($written)->not->toContain("\e[");
});

test('fixture cleanup refuses a missing ownership marker and linked ancestors', function (): void {
    $fixture = new ArchiveFixture();
    $other = new ArchiveFixture();
    $marker = $fixture->path('.owner');
    $token = file_get_contents($marker);
    try {
        $outside = $other->input('keep.txt', 'keep me');
        unlink($marker);
        expect($fixture->dispose(...))->toThrow(RuntimeException::class, 'unowned fixture');
        file_put_contents($marker, $token);
        symlink(dirname($outside), $fixture->path('linked'));
        expect(fn () => $fixture->destroy('linked/keep.txt'))->toThrow(RuntimeException::class, 'linked fixture');
        expect(file_get_contents($outside))->toBe('keep me');
    } finally {
        file_put_contents($marker, $token);
        $fixture->dispose();
        $other->dispose();
    }
});

test('a failed supervised child propagates its status and strict result validation fails', function (): void {
    $process = proc_open([PHP_BINARY, '-r', 'fwrite(STDERR, "worker failed"); exit(7);'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $result = finish_configuration_cli(['process' => $process, 'pipes' => $pipes, 'tick' => static fn () => null, 'worker' => true]);
    expect($result['code'])->toBe(7);
    expect(fn () => configuration_result($result))->toThrow(ExpectationFailedException::class);
});

test('a failing progress observer kills and reaps its active worker', function (): void {
    $process = proc_open([PHP_BINARY, '-r', 'sleep(60);'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $pid = proc_get_status($process)['pid'];
    expect(fn () => finish_configuration_cli([
        'process' => $process,
        'pipes' => $pipes,
        'worker' => true,
        'tick' => static fn () => throw new RuntimeException('cancelled'),
    ]))
        ->toThrow(RuntimeException::class, 'cancelled');
    expect(is_resource($process))->toBeFalse();
    expect(posix_kill($pid, 0))->toBeFalse();
});
