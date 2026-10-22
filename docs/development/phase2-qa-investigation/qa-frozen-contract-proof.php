<?php
require dirname(__DIR__) . '/vendor/autoload.php';
use Tests\Support\RecordsAndArchives\TransactionProbe;

$workspace = sys_get_temp_dir() . '/stashd-frozen-type-proof-' . bin2hex(random_bytes(8));
mkdir($workspace, 0700);
try {
    file_put_contents($workspace . '/list.json', '[1,2]');
    $decoded = TransactionProbe::json($workspace . '/list.json');
    if (array_keys($decoded) !== [0, 1]) {
        throw new RuntimeException('Expected the unchanged JSON helper to accept list keys.');
    }
    echo json_encode(['json_helper' => ['returned' => $decoded, 'keys' => array_keys($decoded), 'declared' => 'array<string, mixed>', 'matches_declared_key_type' => false]], JSON_THROW_ON_ERROR), "\n";

    $child = TransactionProbe::start($workspace, ['operation' => 'probe.ownership']);
    $deadline = microtime(true) + 5;
    do {
        $status = proc_get_status($child['process']);
        if (!$status['running']) { break; }
        usleep(10000);
    } while (microtime(true) < $deadline);
    if ($status['running']) {
        proc_terminate($child['process'], 9);
        proc_close($child['process']);
        throw new RuntimeException('Probe child timed out.');
    }
    unlink($child['prefix'] . '.out');
    unlink($child['prefix'] . '.err');
    $result = TransactionProbe::finish($child);
    if ($result['out'] !== false || $result['err'] !== false) {
        throw new RuntimeException('Expected failed reads to remain false in the unchanged result.');
    }
    echo json_encode(['finish_helper' => ['out_type' => get_debug_type($result['out']), 'err_type' => get_debug_type($result['err']), 'declared_out_err' => 'string', 'matches_declared_value_types' => false]], JSON_THROW_ON_ERROR), "\n";
} finally {
    foreach (glob($workspace . '/*') as $path) {
        if (is_file($path)) { unlink($path); }
    }
    rmdir($workspace);
}
