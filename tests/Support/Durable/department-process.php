<?php

declare(strict_types=1);

use App\Integration\OperationsConfig;
use App\Operations\Durable\DurableRuntime;
use App\Operations\Priority;
use App\Operations\Worker;
use Tests\Support\Durable\DepartmentFixture;
use Tests\Support\Durable\DepartmentJob;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
try {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    if (! preg_match('/^pgsql:host=lerd-postgres;port=5432;dbname=stashd_tx_test_[a-f0-9]{16}$/D', $input['dsn'])) {
        throw new RuntimeException('Only disposable department databases are allowed.');
    }

    $container = DepartmentFixture::boot(new OperationsConfig($input['dsn'], $input['username'], $input['password']));
    $container->singleton(
        \Tests\Support\Durable\DepartmentControl::class,
        new \Tests\Support\Durable\DepartmentControl($input['handlerBarrier'] ?? null, $input['fail'] ?? false, $input['insideMutation'] ?? false),
    );
    if (isset($input['group'])) {
        $container
            ->get(\App\Operations\Durable\DurableConnections::class)
            ->application->executeStatement('SET application_name TO ' . $container->get(\App\Operations\Durable\DurableConnections::class)->application->quote($input['group']));
    }

    if (($input['action'] ?? 'work') === 'admit') {
        if (isset($input['barrier'])) {
            file_put_contents($input['barrier'] . '.' . getmypid(), 'ready');
            $deadline = microtime(true) + 10;
            while (! is_file($input['barrier'] . '.go')) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Admission barrier timed out.');
                }

                usleep(10_000);
            }
        }

        $assignment = $container->get(Worker::class)->do(
            new DepartmentJob($input['scope'], $input['observation'] ?? 'one', $input['mode'] ?? 'refresh'),
            Priority::from($input['priority'] ?? 'routine'),
        );
        echo '\nRECEIPT:' . json_encode(['id' => $assignment->id], JSON_THROW_ON_ERROR) . PHP_EOL;
    } else {
        $runtime = $container->get(DurableRuntime::class);
        $runtime->drain('outbox');
        $runtime->drain(limit: $input['limit'] ?? null);
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
