<?php

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use App\RecordsAndArchives\AssetArchive;
use App\RecordsAndArchives\ItemArchive;
use App\RecordsAndArchives\ItemIdentity;
use App\RecordsAndArchives\Storage\Providers\LocalArchiveStorage;
use App\RecordsAndArchives\Vault;
use Tempest\Clock\ClockInitializer;
use Tempest\Container\GenericContainer;
use Tempest\Storage\Config\LocalStorageConfig;
use Tempest\Storage\GenericStorage;

[$script, $prefix, $stop, $pause] = $argv;
$request = json_decode(file_get_contents($prefix . '.json'), true, flags: JSON_THROW_ON_ERROR);
// This callback pauses only; the supervising parent performs the actual SIGKILL.
$boundary = static function (string $name) use ($prefix, $stop, $pause): void {
    file_put_contents($prefix . '.events', $name . "\n", FILE_APPEND);
    if ($name === $stop) {
        file_put_contents($prefix . '.boundary', $name);
        while (true) {
            usleep(10_000);
        }
    }
    if ($name === $pause) {
        file_put_contents($prefix . '.paused', $name);
        $deadline = microtime(true) + 30;
        while (! is_file($prefix . '.resume')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Authority pause timed out.');
            }
            usleep(10_000);
        }
    }
};
try {
    if ($request['operation'] === 'probe.ownership') {
        // Supervisor self-check only: a real flock, with no archival operations or receipts.
        $lock = fopen(dirname($request['root']) . '/authority.lock', 'c');
        if (! ($request['bypass_lock'] ?? false) && ! flock($lock, LOCK_EX | LOCK_NB)) {
            $boundary('authority-contended');
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Could not acquire probe lock.');
            }
        }
        $boundary('authority-held');
        $result = ['status' => 'probe-complete'];
        flock($lock, LOCK_UN);
        fclose($lock);
    } elseif ($request['operation'] !== 'legacy.publish') {
        $adapter = getenv('STASHD_TRANSACTION_TEST_ADAPTER');
        if (! $adapter) {
            fwrite(STDERR, 'UNIMPLEMENTED: recoverable preservation transaction boundary: ' . $request['operation']);
            exit(78);
        }
        $execute = require $adapter;
        $result = $execute($request, $boundary);
    } else {
        // Baseline probe only: existing services, deliberately no transaction or recovery engine.
        $container = new GenericContainer();
        $container->addInitializer(ClockInitializer::class);
        GenericContainer::setInstance($container);
        $config = new LocalStorageConfig(path: $request['root'], tag: 'vault');
        $vault = new Vault(new LocalArchiveStorage(new GenericStorage($config), $config));
        $identity = new ItemIdentity($vault);
        $assets = new AssetArchive($vault, $identity);
        $items = new ItemArchive($vault, $identity, $assets);
        $pins = [];
        foreach ($request['changes'] as $key => $source) {
            $asset = $assets->preserve($request['uri'], $key, ['data.bin' => $source]);
            $pins[] = ['object_id' => $asset['object_id'], 'version' => $asset['version']];
            $boundary('asset-verified:' . $key);
        }
        $item = $items->commitRevision($request['uri'], $request['metadata'], $pins);
        $boundary('item-verified');
        $result = ['item' => ['object_id' => $item->id(), 'version' => $item->head()]];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage());
    exit(1);
}
