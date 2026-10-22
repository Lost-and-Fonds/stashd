<?php
require dirname(__DIR__) . '/vendor/autoload.php';
use Tests\Support\RecordsAndArchives\TransactionProbe as Probe;
$workspace = __DIR__ . '/pr59-independent-evidence';
$adapter = require dirname(__DIR__) . '/tests/Support/RecordsAndArchives/transaction-adapter.php';
putenv('STASHD_TRANSACTION_TEST_ADAPTER=' . dirname(__DIR__) . '/tests/Support/RecordsAndArchives/transaction-adapter.php');
file_put_contents($workspace . '/numeric.bin', 'numeric master bytes');
$request = ['operation' => 'execute', 'root' => $workspace . '/vault', 'transaction_id' => 'independent-numeric', 'uri' => 'urn:example:transaction:numeric', 'changes' => ['master' => $workspace . '/numeric.bin'], 'metadata' => ['measurement' => 1.0, 'zero' => 0, 'nested' => ['value' => 2.0]], 'preconditions' => []];
$receipt = $adapter($request, static function (string $name): void {});
file_put_contents($workspace . '/numeric-receipt.json', json_encode($receipt, JSON_THROW_ON_ERROR));
$before = Probe::snapshot($workspace . '/vault');
$replayed = Probe::run($workspace, ['operation' => 'recover', 'transaction_id' => $request['transaction_id']]);
if ($replayed !== $receipt || Probe::snapshot($workspace . '/vault') !== $before) { throw new RuntimeException('Numeric replay changed evidence.'); }
unlink($workspace . '/numeric.bin');
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace . '/vault', FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!$file->isFile()) { continue; }
    $relative = substr($file->getPathname(), strlen($workspace . '/vault/'));
    $target = $workspace . '/stages/numeric/' . $relative;
    if (!is_dir(dirname($target))) { mkdir(dirname($target), 0700, true); }
    copy($file->getPathname(), $target);
}
echo "Numeric native-float request completed; fresh-process replay unchanged.\n";
