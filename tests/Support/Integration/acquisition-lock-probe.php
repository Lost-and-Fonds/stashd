<?php

declare(strict_types=1);

use App\Integration\DiscoveryStore;
use Tempest\Console\ConsoleApplication;
use Tempest\Container\GenericContainer;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

if (! preg_match('/^pgsql:host=lerd-postgres;port=5432;dbname=stashd_tx_test_[a-f0-9]{16}$/D', getenv('STASHD_OPERATIONS_DSN') ?: '')) {
    throw new RuntimeException('Lock probes require the generated disposable database.');
}

ConsoleApplication::boot();
$store = GenericContainer::instance()->get(DiscoveryStore::class);
[$script, $operation, $input, $source] = $argv;
try {
    if ($operation === 'scan') {
        $store->lock($input);
        $store->unlock($input);
    } else {
        $store->lockAcquisition($input, $source);
        $store->unlockAcquisition($input, $source);
    }

    echo json_encode(['acquired' => true], JSON_THROW_ON_ERROR);
} catch (RuntimeException) {
    echo json_encode(['acquired' => false], JSON_THROW_ON_ERROR);
}
