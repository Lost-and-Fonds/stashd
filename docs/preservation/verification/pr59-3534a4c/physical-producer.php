<?php
require dirname(__DIR__) . '/vendor/autoload.php';
use App\RecordsAndArchives\Vault;
use App\RecordsAndArchives\Storage\Providers\LocalArchiveStorage;
use Tempest\Clock\ClockInitializer;
use Tempest\Container\GenericContainer;
use Tempest\Storage\Config\LocalStorageConfig;
use Tempest\Storage\GenericStorage;
use Tests\Support\RecordsAndArchives\TransactionProbe as Probe;

$workspace = __DIR__ . '/pr59-independent-evidence';
if (file_exists($workspace)) { throw new RuntimeException('Evidence destination already exists.'); }
mkdir($workspace, 0700);
putenv('STASHD_TRANSACTION_TEST_ADAPTER=' . dirname(__DIR__) . '/tests/Support/RecordsAndArchives/transaction-adapter.php');
$container = new GenericContainer();
$container->addInitializer(ClockInitializer::class);
GenericContainer::setInstance($container);
$config = new LocalStorageConfig(path: $workspace . '/vault', tag: 'vault');
(new Vault(new LocalArchiveStorage(new GenericStorage($config), $config)))->create();
$save = static function (string $name, mixed $value) use ($workspace): void {
    file_put_contents($workspace . '/' . $name . '.json', json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION));
};
$capture = static function (string $name) use ($workspace, $save): void {
    $save($name . '-snapshot', Probe::snapshot($workspace . '/vault'));
    $destination = $workspace . '/stages/' . $name;
    mkdir($destination, 0700, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace . '/vault', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile()) { continue; }
        $relative = substr($file->getPathname(), strlen($workspace . '/vault/'));
        $target = $destination . '/' . $relative;
        if (!is_dir(dirname($target))) { mkdir(dirname($target), 0700, true); }
        copy($file->getPathname(), $target);
    }
};
$run = static fn (string $operation, string $id = 'independent-3534a4c'): array => Probe::run($workspace, ['operation' => $operation, 'transaction_id' => $id]);
file_put_contents($workspace . '/master.bin', 'independently verified master bytes');
file_put_contents($workspace . '/sidecar.bin', 'independently verified sidecar bytes');
$request = ['operation' => 'prepare', 'transaction_id' => 'independent-3534a4c', 'uri' => 'urn:example:transaction:item', 'changes' => ['master' => $workspace . '/master.bin', 'sidecar' => $workspace . '/sidecar.bin'], 'metadata' => ['label' => 'independent verification', 'numeric' => 1.0], 'preconditions' => []];
$save('prepared', Probe::run($workspace, $request));
$save('commit-crash', Probe::finish(Probe::start($workspace, ['operation' => 'commit', 'transaction_id' => $request['transaction_id']], 'asset-verified:master')));
$capture('commit-crash');
unlink($workspace . '/master.bin');
unlink($workspace . '/sidecar.bin');
$save('recovered', $run('recover'));
$capture('complete');
$save('replayed', $run('commit'));
$capture('replay');
$save('release-crash', Probe::finish(Probe::start($workspace, ['operation' => 'custody.release', 'transaction_id' => $request['transaction_id']], 'custody-released:master')));
$capture('release-crash');
$save('release-crash-recovered', $run('recover'));
$save('released', $run('custody.release'));
$capture('released');
$save('released-replayed', $run('commit'));
$save('released-recovered', $run('recover'));
$capture('released-replay');
file_put_contents($workspace . '/updated.bin', 'updated sidecar bytes');
$update = [...$request, 'transaction_id' => 'independent-lost-ack', 'changes' => ['sidecar' => $workspace . '/updated.bin'], 'metadata' => ['label' => 'lost acknowledgement']];
$save('lost-ack-prepared', Probe::run($workspace, $update));
$save('lost-ack-crash', Probe::finish(Probe::start($workspace, ['operation' => 'commit', 'transaction_id' => $update['transaction_id']], 'after-ack')));
$capture('lost-ack');
unlink($workspace . '/updated.bin');
$save('lost-ack-recovered', $run('recover', $update['transaction_id']));
$save('lost-ack-replayed', $run('commit', $update['transaction_id']));
$capture('lost-ack-replay');
echo json_encode(['status' => 'evidence-collected', 'workspace' => $workspace], JSON_THROW_ON_ERROR), "\n";
