<?php

declare(strict_types=1);

use App\Integration\OperationsConfig;
use Tests\Support\HistoricalOperations\JobRuntime;
use Tests\Support\HistoricalOperations\Jobs;
use Tests\Support\HistoricalOperations\Work;
use Tests\Support\Operations\LocalOperationsDriver;
use Tests\Support\Operations\ProbeExecutor;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

try {
    $request = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $descriptor = $request['descriptor'];
    $database = LocalOperationsDriver::connect($descriptor);
    $runtime = new JobRuntime(new OperationsConfig($descriptor['dsn'], $descriptor['username'], $descriptor['password'], $descriptor['schema']), new ProbeExecutor($database));
    $result = [];
    if ($request['action'] === 'request') {
        $work = $request['work'];
        $result['job_id'] = new Jobs($runtime)->request(new Work('acceptance.probe', $work['scope_id'], $work['mode'], $work['observation']), $work['priority']);
    } elseif ($request['action'] === 'work') {
        $runtime->work($request['receiver'], true, $request['limit'] ?? null);
    } else {
        throw new RuntimeException('Unsupported delivery child action.');
    }

    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
