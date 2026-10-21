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

[$script, $prefix, $stop, $gate] = $argv;
$request = json_decode(file_get_contents($prefix . '.json'), true, flags: JSON_THROW_ON_ERROR);
// This callback pauses only; the supervising parent performs the actual SIGKILL.
$boundary = static function (string $name) use ($prefix, $stop): void {
    if ($name === $stop) {
        file_put_contents($prefix . '.boundary', $name);
        while (true) {
            usleep(10_000);
        }
    }
};
if ($gate !== '') {
    file_put_contents($prefix . '.ready', 'ready');
    $deadline = microtime(true) + 10;
    while (! is_file($gate)) {
        if (microtime(true) > $deadline) {
            fwrite(STDERR, 'Start gate timed out');
            exit(1);
        }
        usleep(10_000);
    }
}
try {
    if ($request['operation'] !== 'legacy.publish') {
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
