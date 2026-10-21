<?php

use App\RecordsAndArchives\AssetArchive;
use App\RecordsAndArchives\ItemArchive;
use App\RecordsAndArchives\ItemIdentity;
use App\RecordsAndArchives\PreservationTransaction;
use App\RecordsAndArchives\Storage\Providers\LocalArchiveStorage;
use App\RecordsAndArchives\Vault;
use Tempest\Clock\ClockInitializer;
use Tempest\Container\GenericContainer;
use Tempest\Storage\Config\LocalStorageConfig;
use Tempest\Storage\GenericStorage;

// Test vocabulary ends here; publication and recovery belong to production Core.
return static function (array $request, Closure $boundary): array {
    $container = new GenericContainer();
    $container->addInitializer(ClockInitializer::class);
    GenericContainer::setInstance($container);
    $config = new LocalStorageConfig(path: $request['root'], tag: 'vault');
    $storage = isset($request['crash_boundary'])
        ? new Tests\Support\RecordsAndArchives\CrashStorage(new GenericStorage($config), $config)
        : new LocalArchiveStorage(new GenericStorage($config), $config);
    $vault = new Vault($storage);
    if (isset($request['crash_boundary'])) {
        if (! is_string($request['crash_boundary']) || ! in_array($request['crash_target'] ?? null, ['asset', 'item'], true)) {
            throw new InvalidArgumentException('Crash fixture requires a boundary and Asset or Item target.');
        }
        $observe = $boundary;
        $boundary = static function (string $name) use ($request, $observe): void {
            if ($name === ($request['crash_target'] === 'item' ? 'before-item' : 'before-asset:master')) {
                putenv('CRASH_BOUNDARY=' . $request['crash_boundary']);
            }
            $observe($name);
        };
    }
    $identity = new ItemIdentity($vault);
    $assets = new AssetArchive($vault, $identity);
    $items = new ItemArchive($vault, $identity, $assets);
    $transactions = new PreservationTransaction($vault, $items, $assets);
    if (str_starts_with($request['operation'], 'projection.')) {
        return new Tests\Support\RecordsAndArchives\TransactionProjectionFixture()->run($request, $vault, $items, $assets);
    }
    if ($request['operation'] === 'discover') {
        return $transactions->discover();
    }
    if (in_array($request['operation'], ['prepare', 'execute'], true)) {
        $prepared = $transactions->prepare($request['transaction_id'], $request['uri'], $request['changes'], $request['metadata'], $request['preconditions'], $boundary);
        if ($request['operation'] === 'prepare' || $prepared['status'] !== 'sealed') {
            return $prepared;
        }
    } elseif (! in_array($request['operation'], ['commit', 'recover'], true)) {
        throw new InvalidArgumentException('Unsupported transaction fixture operation.');
    }
    return $transactions->commit($request['transaction_id'], $boundary);
};
