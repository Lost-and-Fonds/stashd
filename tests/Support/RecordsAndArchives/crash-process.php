<?php

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use App\RecordsAndArchives\AssetArchive;
use App\RecordsAndArchives\ItemArchive;
use App\RecordsAndArchives\ItemIdentity;
use App\RecordsAndArchives\Vault;
use Tempest\Clock\ClockInitializer;
use Tempest\Container\GenericContainer;
use Tempest\Storage\Config\LocalStorageConfig;
use Tempest\Storage\GenericStorage;
use Tests\Support\RecordsAndArchives\CrashStorage;

$container = new GenericContainer();
$container->addInitializer(ClockInitializer::class);
GenericContainer::setInstance($container);
$config = new LocalStorageConfig(path: $argv[1], tag: 'vault');
$storage = new CrashStorage(new GenericStorage($config), $config);
$vault = new Vault($storage);
$identity = new ItemIdentity($vault);
$assets = new AssetArchive($vault, $identity);
$items = new ItemArchive($vault, $identity, $assets);
[$operation, $kind] = [$argv[2], $argv[3]];
$uri = 'https://example.test/crash';
$id = $kind === 'item' ? $items->objectIdForUri($uri) : $assets->objectId($uri, 'original');
try {
    if ($operation === 'write') {
        $result = $kind === 'item'
            ? ['version' => $items->commitRevision($uri, ['revision' => $argv[4]])->head()]
            : $assets->preserve($uri, 'original', ['payload.bin' => $argv[4]]);
    } elseif ($operation === 'read-version') {
        $result = $kind === 'item' ? $items->read($id, $argv[4]) : $assets->inspect($id, $argv[4]);
    } else {
        $state = $vault->inspectObject($id);
        $result = ['object_id' => $id->toString(), ...$state];
        if ($state['head'] !== null) {
            foreach ($state['versions'] as $version) {
                $result['records'][$version] = $kind === 'item' ? $items->read($id, $version) : $assets->inspect($id, $version);
            }

            $result['record'] = $kind === 'item' ? $items->read($id, 'v1') : $assets->inspect($id, 'v1');
        }
    }

    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage());
    exit(1);
}
