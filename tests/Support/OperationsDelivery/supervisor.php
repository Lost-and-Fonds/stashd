<?php

declare(strict_types=1);

use Symfony\Component\Process\Exception\ProcessSignaledException;
use Tempest\DateTime\Duration;
use Tempest\Process\GenericProcessExecutor;
use Tempest\Process\PendingProcess;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// A separate bounded supervisor owns the process handle across fresh driver objects.
$prefix = $argv[1];
$child = null;
try {
    $child = new GenericProcessExecutor()->start(new PendingProcess(
        command: [PHP_BINARY, __DIR__ . '/runtime-process.php'],
        input: file_get_contents($prefix . '.request'),
        path: dirname(__DIR__, 3),
        timeout: Duration::seconds(25),
        environment: ['ENVIRONMENT' => 'testing', 'PGAPPNAME' => basename($prefix)],
    ));
    file_put_contents($prefix . '.pid', (string) $child->pid);
    $deadline = microtime(true) + 20;
    $killed = false;
    while ($child->running && microtime(true) < $deadline) {
        if (is_file($prefix . '.kill')) {
            $child->signal(9);
            $killed = true;
            break;
        }

        usleep(10_000);
    }

    if (! $killed && $child->running) {
        throw new RuntimeException('Delivery supervisor timed out.');
    }

    $result = $child->wait();
    file_put_contents($prefix . '.done', json_encode(['killed' => $killed, 'exit' => $result->exitCode, 'error' => $result->errorOutput], JSON_THROW_ON_ERROR));
} catch (ProcessSignaledException $error) {
    file_put_contents($prefix . '.done', json_encode(['killed' => $killed, 'exit' => 128 + $error->getSignal(), 'error' => $error->getMessage()], JSON_THROW_ON_ERROR));
} catch (Throwable $error) {
    file_put_contents($prefix . '.done', json_encode(['killed' => false, 'exit' => 1, 'error' => $error->getMessage()], JSON_THROW_ON_ERROR));
} finally {
    $child?->stop(0, 9);
    file_put_contents($prefix . '.reaped', 'reaped');
}
