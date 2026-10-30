<?php

declare(strict_types=1);

use App\Integration\OperationsConfig;
use App\Operations\JobRuntime;
use App\Operations\Jobs;
use App\Operations\Work;
use Tests\Support\Operations\LocalOperationsDriver;
use Tests\Support\Operations\ProbeExecutor;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

try {
    $request = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $descriptor = $request['descriptor'];
    $database = LocalOperationsDriver::connect($descriptor);
    $runtime = new JobRuntime(new OperationsConfig($descriptor['dsn'], $descriptor['username'], $descriptor['password'], $descriptor['schema']), new ProbeExecutor($database));
    $result = [];
    if ($request['action'] === 'initialize') {
        // Provision the native channel before concurrent admission starts.
    } elseif ($request['action'] === 'request') {
        $work = $request['work'];
        $result = ['job_id' => new Jobs($runtime)->request(new Work('acceptance.probe', $work['scope_id'], $work['mode'], $work['observation']), $work['priority'])];
    } elseif ($request['action'] === 'execute') {
        $runtime->work('jobs', true, $request['limit']);
    } else {
        throw new RuntimeException('Unsupported child action.');
    }

    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
