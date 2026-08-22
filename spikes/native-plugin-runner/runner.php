<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "M1 runner requires CLI PHP\n");
    exit(2);
}

[$timeout, $stagingRoot, $packageRoot, $entrypoint] = parseArguments(array_slice($argv, 1));
$packageRoot = realpath($packageRoot) ?: fail('package root does not exist');
$stagingRoot = realpath($stagingRoot) ?: fail('staging root does not exist');
$entrypoint = validateRelativePath($entrypoint, 'entrypoint');
$entrypointPath = $packageRoot . '/' . $entrypoint;
if (! is_file($entrypointPath)) {
    fail('entrypoint does not exist in package root');
}

$jobDirectory = createJobDirectory($stagingRoot);
$etcDirectory = $jobDirectory . '/.etc';
if (! mkdir($etcDirectory, 0700) && ! is_dir($etcDirectory)) {
    removeTree($jobDirectory);
    fail('could not create minimal etc directory');
}
file_put_contents($etcDirectory . '/passwd', "root:x:0:0:root:/root:/bin/sh\nplugin:x:1000:1000:plugin:/tmp:/bin/sh\n");
file_put_contents($etcDirectory . '/group', "root:x:0:\nplugin:x:1000:\n");

$command = [
    'bwrap',
    '--die-with-parent',
    '--new-session',
    '--unshare-user',
    '--unshare-pid',
    '--unshare-ipc',
    '--unshare-uts',
    '--unshare-net',
    '--clearenv',
    '--ro-bind', $packageRoot, '/plugin',
    '--bind', $jobDirectory, '/staging',
    '--tmpfs', '/tmp',
    '--dev', '/dev',
    '--ro-bind', '/usr', '/usr',
    '--ro-bind', '/bin', '/bin',
    '--ro-bind', '/lib', '/lib',
    '--ro-bind', '/lib64', '/lib64',
    '--ro-bind', '/sbin', '/sbin',
    '--ro-bind', $etcDirectory, '/etc',
    '--dir', '/home',
    '--dir', '/root',
    '--dir', '/run',
    '--chdir', '/plugin',
    '--setenv', 'HOME', '/tmp',
    '--setenv', 'PATH', '/usr/local/bin:/usr/bin:/bin',
    '--',
    'php', '/plugin/' . $entrypoint,
];

$pipes = [];
$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (! is_resource($process)) {
    removeTree($jobDirectory);
    fail('could not start bubblewrap');
}

fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
$stdout = '';
$stderr = '';
$timedOut = false;
$deadline = microtime(true) + $timeout;

try {
    while (true) {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (! $status['running']) {
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            proc_terminate($process, 15);
            $graceDeadline = microtime(true) + 0.25;
            do {
                usleep(10_000);
                $status = proc_get_status($process);
            } while ($status['running'] && microtime(true) < $graceDeadline);
            if ($status['running']) {
                proc_terminate($process, 9);
            }
            break;
        }
        usleep(10_000);
    }

    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    $exitCode = proc_close($process);
} finally {
    if (is_resource($pipes[1])) {
        fclose($pipes[1]);
    }
    if (is_resource($pipes[2])) {
        fclose($pipes[2]);
    }
}

$reportPath = $jobDirectory . '/report.json';
$report = is_file($reportPath) ? json_decode((string) file_get_contents($reportPath), true) : null;
$stagingHadReport = is_array($report);
removeTree($jobDirectory);

$result = [
    'exit_code' => $exitCode,
    'timed_out' => $timedOut,
    'stdout' => $stdout,
    'stderr' => $stderr,
    'report' => $report,
    'staging_clean' => ! file_exists($jobDirectory),
    'report_observed_before_cleanup' => $stagingHadReport,
];
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
exit($timedOut ? 124 : ($exitCode === 0 ? 0 : 20));

/** @return array{0:float,1:string,2:string,3:string} */
function parseArguments(array $arguments): array
{
    $timeout = 10.0;
    $stagingRoot = null;
    $positionals = [];
    for ($index = 0; $index < count($arguments); $index++) {
        $argument = $arguments[$index];
        if ($argument === '--timeout') {
            $timeout = (float) ($arguments[++$index] ?? 0);
        } elseif (str_starts_with($argument, '--timeout=')) {
            $timeout = (float) substr($argument, 10);
        } elseif ($argument === '--staging-root') {
            $stagingRoot = $arguments[++$index] ?? null;
        } elseif (str_starts_with($argument, '--staging-root=')) {
            $stagingRoot = substr($argument, 15);
        } else {
            $positionals[] = $argument;
        }
    }
    if ($timeout <= 0 || $stagingRoot === null || count($positionals) !== 2) {
        fail('usage: runner.php --staging-root DIR --timeout SECONDS PACKAGE ENTRYPOINT');
    }

    return [$timeout, $stagingRoot, $positionals[0], $positionals[1]];
}

function validateRelativePath(string $path, string $label): string
{
    if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")) {
        fail("{$label} must be relative");
    }
    $parts = explode('/', $path);
    if (in_array('..', $parts, true) || in_array('', $parts, true)) {
        fail("{$label} contains an unsafe segment");
    }

    return $path;
}

function createJobDirectory(string $root): string
{
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $path = $root . '/job-' . bin2hex(random_bytes(12));
        if (mkdir($path, 0700)) {
            return $path;
        }
    }
    fail('could not create per-job staging directory');
}

function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (! is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        removeTree($path . '/' . $entry);
    }
    @rmdir($path);
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(2);
}
