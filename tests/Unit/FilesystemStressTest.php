<?php

declare(strict_types=1);

use App\Development\DTO\FilesystemStressProgress;
use App\Development\FilesystemStressCommand;
use App\Development\FilesystemStressPresenter;
use PHPUnit\Framework\ExpectationFailedException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\BufferedOutput;
use Tempest\Console\Console;
use Tempest\Core\Environment;
use Tests\Support\Integration\FilesystemStressRunner;
use Tests\Support\Integration\ProcessTreeRssTrace;
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
    $presenter->update(new FilesystemStressProgress('acquisition', 10, 100, 100, 100, 0, 0, 0, 0, 0, null, 100, 100, 'Jobs settled (not preservation proof)'));
    $report = ['format' => 'stashd-filesystem-stress-1', 'status' => 'failed', 'failure_type' => RuntimeException::class];
    $presenter->finish($report, true);
    expect(json_decode($written, true, flags: JSON_THROW_ON_ERROR))->toBe($report);
    expect($written)->not->toContain("\e[");
});

test('the live bar follows each phase and resets despite previously verified output', function (): void {
    $console = $this->createStub(Console::class);
    $console->method('writeRaw')->willReturn($console);
    $presenter = new FilesystemStressPresenter($console, false);
    $output = new BufferedOutput(decorated: true);
    $bar = new ProgressBar($output, 100);
    new ReflectionProperty($presenter, 'bar')->setValue($presenter, $bar);

    foreach ([
        ['discovery',                   30,   100, 'distinct sources discovered'],
        ['first_acquisition',           null, 100, 'Jobs settled (not preservation proof)'],
        ['first_acquisition',           60,   100, 'Jobs settled (not preservation proof)'],
        ['verify_preservation',         0,    100, 'Items independently checked'],
        ['verify_preservation',         100,  100, 'Items independently checked'],
        ['verify_first_publication',    0,    100, 'output files independently checked'],
        ['verify_first_publication',    100,  100, 'output files independently checked'],
        ['repeat_broadcast',            0,    1,   'publication Job settled (not byte verification)'],
        ['publication_reports',         40,   100, 'file reports checked (not byte verification)'],
        ['verify_repeat_publication',   0,    100, 'output files and stable inodes independently checked'],
        ['verify_repeat_publication',   100,  100, 'output files and stable inodes independently checked'],
        ['corruption_negative_control', 0,    1,   'deliberate corruption rejected'],
        ['corruption_negative_control', 1,    1,   'deliberate corruption rejected'],
        ['cleanup',                     0,    1,   'fixture removed'],
    ] as [$phase, $completed, $total, $unit]) {
        // Historical verified counts must never fill a different phase's bar.
        $presenter->update(new FilesystemStressProgress($phase, 10, 100, 100, 100, 100, 100, 0, 0, 0, null, $completed, $total, $unit));
        expect($bar->getProgress())->toBe($completed ?? 0);
        expect($bar->getMaxSteps())->toBe($total);
        $rendered = $output->fetch();
        expect($rendered)->toContain($phase, ($completed ?? '?') . '/' . $total, $unit);
        expect($rendered)->not->toContain('PASS:');
    }

    $presenter->finish(['status' => 'failed'], true);
    expect($output->fetch())->not->toContain('PASS:');
});

test('diagnostics clear and restore the live display without advancing its counter', function (): void {
    $presenter = new FilesystemStressPresenter($this->createStub(Console::class), false);
    $output = new BufferedOutput(decorated: true);
    $bar = new ProgressBar($output, 100);
    $bar->setFormat('%bar% %current%/%max%');
    $bar->setProgress(42);
    new ReflectionProperty($presenter, 'bar')->setValue($presenter, $bar);
    $output->fetch();

    $presenter->diagnostic("Verifier diagnostic.\n");

    expect($bar->getProgress())->toBe(42);
    expect($output->fetch())->toContain("\e[2K", '42/100');
});

test('interactive progress stays within narrow terminal columns', function (int $columns): void {
    $previous = getenv('COLUMNS');
    try {
        putenv('COLUMNS=' . $columns);
        $console = $this->createStub(Console::class);
        $presenter = new FilesystemStressPresenter($console, false);
        $output = new BufferedOutput(decorated: true);
        $bar = new ProgressBar($output, 100);
        new ReflectionProperty($presenter, 'bar')->setValue($presenter, $bar);
        $presenter->update(new FilesystemStressProgress(
            'verify_repeat_publication',
            123.4,
            100,
            100,
            100,
            100,
            100,
            0,
            0,
            1,
            123_456,
            0,
            100,
            'output files and stable inodes independently checked',
        ));
        foreach (explode("\n", $bar->getMessage()) as $line) {
            expect(strlen($line))->toBeLessThan($columns);
        }

        foreach (explode("\n", $bar->getMessage('phase_unit')) as $line) {
            expect(strlen($line))->toBeLessThan($columns);
        }

        expect($bar->getBarWidth() + 1 + strlen($bar->getMessage('phase_progress')))->toBeLessThan($columns);
    } finally {
        putenv($previous === false ? 'COLUMNS' : 'COLUMNS=' . $previous);
    }
})->with([40, 80, 100]);

test('interactive setup declines safely with Tempest rich prompts', function (): void {
    if (! shell_exec('command -v tput')) {
        $this->markTestSkipped('The rich terminal test requires tput.');
    }

    $script = <<<'PYTHON'
    import errno, fcntl, os, pty, select, struct, subprocess, sys, termios, time
    master, slave = pty.openpty()
    fcntl.ioctl(slave, termios.TIOCSWINSZ, struct.pack('HHHH', 24, 80, 0, 0))
    child = subprocess.Popen([sys.argv[1], 'tempest', 'stress:filesystem'], stdin=slave, stdout=slave, stderr=slave, env={**os.environ, 'TERM': 'xterm-256color'})
    os.close(slave)
    output = b''
    try:
        declined = False
        deadline = time.monotonic() + 10
        while time.monotonic() < deadline:
            if not select.select([master], [], [], 0.1)[0]:
                if child.poll() is not None:
                    break
                continue
            try:
                chunk = os.read(master, 65536)
            except OSError as error:
                if error.errno == errno.EIO:
                    break
                raise
            output += chunk
            if b'\x1b[6n' in chunk:
                os.write(master, b'\x1b[1;1R')
            if b'Run the 100-Item stress test?' in output and not declined:
                os.write(master, b'\r')
                declined = True
        assert child.wait(timeout=1) == 1, output
        assert b'Run the 100-Item stress test?' in output, output
        assert b'tput:' not in output and b'\x1b[6n' in output, output
        assert b'generate_source' not in output and b'PASS:' not in output, output
        print('Tempest rich setup declined safely in an 80-column terminal.')
    finally:
        if child.poll() is None:
            child.kill()
        child.wait(timeout=5)
        os.close(master)
    PYTHON;
    $process = proc_open(
        ['python3', '-c', $script, PHP_BINARY],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        env_vars: [...getenv(), 'ENVIRONMENT' => 'testing', 'SIGNING_KEY' => base64_encode(str_repeat('x', 32))],
    );
    $result = finish_configuration_cli(['process' => $process, 'pipes' => $pipes]);
    expect($result['code'])->toBe(0, $result['output'] . $result['errors']);
});

test('live RSS reads only appended samples and keeps an incremental maximum', function (): void {
    $fixture = new ArchiveFixture();
    $path = $fixture->path('trace.jsonl');
    $trace = new ProcessTreeRssTrace($path);
    try {
        expect($trace->peak())->toBeNull();
        $first = json_encode(['rss_kib' => 200], JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($path, $first);
        expect($trace->peak())->toBe(200);
        expect($trace->peak())->toBe(200);

        // A changed prefix proves that a refresh does not reparse consumed history.
        $stream = fopen($path, 'r+');
        fwrite($stream, str_repeat('x', strlen($first)));
        fclose($stream);
        file_put_contents($path, "{\"rss_kib\":100}\n{\"rss_kib\":300}", FILE_APPEND);
        expect($trace->peak())->toBe(200);
        expect($trace->peak())->toBe(200);
        file_put_contents($path, "\n", FILE_APPEND);
        expect($trace->peak())->toBe(300);
        file_put_contents($path, "{\"rss_kib\":250}\n", FILE_APPEND);
        expect($trace->peak())->toBe(300);
    } finally {
        $fixture->dispose();
    }
});

test('malformed complete RSS samples fail instead of masquerading as missing observations', function (): void {
    $fixture = new ArchiveFixture();
    try {
        $path = $fixture->path('trace.jsonl');
        file_put_contents($path, "not json\n");
        expect(fn () => new ProcessTreeRssTrace($path)->peak())->toThrow(JsonException::class);
    } finally {
        $fixture->dispose();
    }
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
