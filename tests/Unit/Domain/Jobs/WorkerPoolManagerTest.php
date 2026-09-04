<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Jobs;

use App\Config\StashdConfig;
use App\Jobs\WorkerPoolManager;

test('worker pool stays within configured limits and backs off under load', function (): void {
    $config = new StashdConfig('', '', '', 'text', 1000, 1000, '0022', '8474', [
        'background' => ['min_workers' => 1, 'max_workers' => 4],
    ]);
    $pool = new WorkerPoolManager($config);

    expect($pool->desiredWorkers('background', 12, 1))->toBe(4)
        ->and($pool->desiredWorkers('background', 12, 4, 0.9))->toBe(1)
        ->and($pool->desiredWorkers('background', 0, 2))->toBe(1);
});
