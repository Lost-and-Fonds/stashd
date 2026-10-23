<?php

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\RecordsAndArchives\TransactionProbe;

$workspace = $argv[1] ?? null;
$mode = $argv[2] ?? null;
if (! is_string($workspace) || ! is_string($mode)) {
    throw new InvalidArgumentException('The contract worker requires a workspace and mode.');
}

// Assertion formatting needs configuration; leave native PHP warning handling unchanged.
\PHPUnit\TextUI\Configuration\Registry::loadFrom($workspace . '/phpunit.configuration');
if (in_array($mode, ['empty', 'nonzero'], true)) {
    $prefix = $workspace . '/child';
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-r', $mode === 'empty' ? 'exit(0);' : 'fwrite(STDOUT, "stdout bytes"); fwrite(STDERR, "stderr bytes"); exit(7);'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $prefix . '.out', 'w'], 2 => ['file', $prefix . '.err', 'w']],
        $pipes,
    );
    Assert::assertIsResource($process);
    $child = ['process' => $process, 'prefix' => $prefix, 'boundary' => ''];
} else {
    $child = TransactionProbe::start($workspace, ['operation' => 'probe.ownership'], $mode === 'crash' ? 'authority-held' : '');
}

try {
    if (in_array($mode, ['missing-stdout', 'missing-stderr', 'missing-both'], true)) {
        $deadline = microtime(true) + 5;
        do {
            $status = proc_get_status($child['process']);
            if (! $status['running']) {
                break;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        Assert::assertFalse($status['running'], 'Fixture child must finish before its log paths are removed.');
        foreach ($mode === 'missing-both' ? ['out', 'err'] : [$mode === 'missing-stdout' ? 'out' : 'err'] as $suffix) {
            Assert::assertTrue(unlink($child['prefix'] . '.' . $suffix));
        }
    }

    if ($mode === 'missing-boundary') {
        $child['boundary'] = 'never-reached';
    }

    try {
        $result = ['status' => 'returned', 'value' => TransactionProbe::finish($child)];
    } catch (AssertionFailedError $error) {
        $result = ['status' => 'rejected', 'message' => $error->getMessage()];
    }

    $result['process_closed'] = ! is_resource($child['process']);
    file_put_contents($workspace . '/result.json', json_encode($result, JSON_THROW_ON_ERROR));
} finally {
    if (is_resource($child['process'])) {
        proc_terminate($child['process'], 9);
        proc_close($child['process']);
    }
}
