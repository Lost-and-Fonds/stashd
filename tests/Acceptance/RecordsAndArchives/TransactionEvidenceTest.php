<?php

use Tests\Support\RecordsAndArchives\ArchiveFixture;
use Tests\Support\RecordsAndArchives\OcflInspector;
use Tests\Support\RecordsAndArchives\PreparationProbe;
use Tests\Support\RecordsAndArchives\TransactionProbe as Probe;

/**
 * Build a disposable candidate without depending on frozen Phase 1 helper functions.
 *
 * @return array<string, mixed>
 */
function evidence_request(ArchiveFixture $archive, string $id = 'evidence'): array
{
    return [
        'operation' => 'execute',
        'transaction_id' => $id,
        'uri' => 'urn:example:transaction:item',
        'changes' => ['master' => $archive->input($id . '.bin', 'accepted bytes')],
        'metadata' => ['label' => $id],
        'preconditions' => [],
    ];
}

/**
 * Initialize one disposable local Vault and return its private workspace.
 */
function evidence_workspace(ArchiveFixture $archive): string
{
    $archive->run('vault.initialize');
    return dirname($archive->path('vault'));
}

/**
 * Read required fixture bytes while treating read failure as a setup error.
 */
function evidence_bytes(string $path): string
{
    $bytes = file_get_contents($path);
    if ($bytes === false) {
        throw new RuntimeException('Could not read fixture evidence.');
    }
    return $bytes;
}

/**
 * Independently bind exact Asset and Item revisions to verified physical journal bytes.
 *
 * Hash the original plan-envelope JSON rather than reproducing production encoding;
 * numeric JSON types and serialization differences must remain visible to this oracle.
 */
function evidence_bindings(string $root, string $candidatePath): void
{
    $candidateBytes = evidence_bytes($candidatePath);
    $candidateDigest = hash('sha512', $candidateBytes);
    expect(file_get_contents(dirname($candidatePath) . '/candidate.sha512'))->toBe($candidateDigest);
    /** @var array{delta: array{id: string, changes: array<string, array{digest: string}>}} $candidate */
    $candidate = json_decode($candidateBytes, true, flags: JSON_THROW_ON_ERROR);
    expect($candidate['delta']['id'])->toBe('evidence');
    expect($candidate['delta']['changes']['master']['digest'])->toBe(hash('sha512', 'accepted bytes'));
    $planEnvelope = Probe::json(dirname($candidatePath) . '/plan.json');
    expect(hash('sha512', $planEnvelope['json']))->toBe($planEnvelope['sha512']);
    /** @var array{candidate: string, item: array{object_id: string, version: string}, assets: array<string, array{object_id: string, version: string}>} $plan */
    $plan = json_decode($planEnvelope['json'], true, flags: JSON_THROW_ON_ERROR);
    expect($plan['candidate'])->toBe($candidateDigest);
    $binding = ['id' => 'evidence', 'candidate' => $candidateDigest, 'plan' => hash('sha512', $planEnvelope['json'])];
    foreach ([$plan['item'], ...array_values($plan['assets'])] as $step) {
        OcflInspector::assertAcknowledgedHistory($root, $step['object_id'], $step['version']);
        $path = str_starts_with($step['object_id'], 'urn:stashd:item:') ? 'item.json' : '.stashd/asset.json';
        $record = Probe::json(OcflInspector::payloadPath($root, $step['object_id'], $step['version'], $path));
        expect($record['transaction'])->toBe($binding);
    }
}

test('independent physical reader proves candidate plan and exact OCFL version linkage', function (): void {
    $workspace = evidence_workspace($this->archive);
    $request = evidence_request($this->archive);
    $prepared = Probe::run($workspace, [...$request, 'operation' => 'prepare']);
    /** @var array{status: string, transaction_id: string, item: array{object_id: string, version: string}} $receipt */
    $receipt = Probe::run($workspace, ['operation' => 'commit', 'transaction_id' => 'evidence']);
    expect($receipt['status'])->toBe('complete');
    evidence_bindings($workspace . '/vault', $prepared['candidate_path']);
    expect(Probe::run($workspace, ['operation' => 'discover'])['ids'])->toBe(['evidence']);
    Probe::committed($workspace . '/vault', $receipt, 'evidence', ['master' => 'accepted bytes']);
});

test('missing malformed or conflicting retained journal evidence cannot authorize publication', function (string $damage): void {
    $workspace = evidence_workspace($this->archive);
    $request = evidence_request($this->archive);
    $prepared = Probe::run($workspace, [...$request, 'operation' => 'prepare']);
    Probe::finish(Probe::start($workspace, ['operation' => 'commit', 'transaction_id' => 'evidence'], 'asset-verified:master'));
    $directory = dirname($prepared['candidate_path']);
    if ($damage === 'missing-plan') {
        unlink($directory . '/plan.json');
    } elseif ($damage === 'missing-seal') {
        unlink($directory . '/candidate.sha512');
    } elseif ($damage === 'missing-custody') {
        unlink($prepared['custody_path']);
    } elseif ($damage === 'malformed-plan') {
        file_put_contents($directory . '/plan.json', '{');
    } else {
        $envelope = Probe::json($directory . '/plan.json');
        /** @var array{assets: array<string, array{version: string}>} $plan */
        $plan = json_decode($envelope['json'], true, flags: JSON_THROW_ON_ERROR);
        $plan['assets']['master']['version'] = 'v99';
        $json = json_encode($plan, JSON_THROW_ON_ERROR);
        file_put_contents($directory . '/plan.json', json_encode(['json' => $json, 'sha512' => hash('sha512', $json)], JSON_THROW_ON_ERROR));
    }
    $before = Probe::snapshot($workspace . '/vault');
    for ($attempt = 0; $attempt < 2; $attempt++) {
        expect(Probe::run($workspace, ['operation' => 'recover', 'transaction_id' => 'evidence'])['status'])->toBe('quarantined');
        expect(Probe::snapshot($workspace . '/vault'))->toBe($before);
    }
})->with(['missing-plan', 'missing-seal', 'missing-custody', 'malformed-plan', 'conflicting-plan']);

test('intra Object process death retains evidence and reuses only acknowledged transaction bound versions', function (string $target, string $boundary): void {
    $workspace = evidence_workspace($this->archive);
    $request = evidence_request($this->archive);
    $killed = Probe::finish(Probe::start($workspace, [...$request, 'crash_target' => $target, 'crash_boundary' => $boundary]));
    expect($killed['signal'])->toBe(9);
    expect($killed['err'])->toContain('CRASH:' . $boundary);
    expect($killed['out'])->toBe('');
    $before = Probe::snapshot($workspace . '/vault');
    $result = Probe::run($workspace, ['operation' => 'recover', 'transaction_id' => 'evidence']);
    // These I/O kills retain pending publication material, including post-sidecar cleanup.
    expect($result['status'])->toBe('quarantined');
    expect(Probe::snapshot($workspace . '/vault'))->toBe($before);
    foreach ($before as $path => $digest) {
        if (! preg_match('~/v[0-9]+/~', $path)) {
            continue;
        }

        expect(Probe::snapshot($workspace . '/vault')[$path] ?? null)->toBe($digest);
    }
})->with(['asset', 'item'])->with([
    'content-partial',
    'content-complete',
    'version-inventory-partial',
    'version-ready',
    'version-published',
    'root-inventory-partial',
    'root-inventory-published',
    'root-sidecar-partial',
    'root-sidecar-published',
    'before-cleanup',
]);

/**
 * Wait for a child to prove its actual authority transition and fail if it exits early.
 *
 * @param array{process: resource, prefix: string, boundary: string} $child
 */
function evidence_paused(array $child, string $name): void
{
    $deadline = microtime(true) + 5;
    while (! is_file($child['prefix'] . '.paused') && microtime(true) < $deadline) {
        expect(proc_get_status($child['process'])['running'])->toBeTrue();
        usleep(10_000);
    }
    expect(file_get_contents($child['prefix'] . '.paused'))->toBe($name);
}

test('an aged local authority cannot fence out its still live owner or admit a second publisher', function (): void {
    $workspace = evidence_workspace($this->archive);
    Probe::run($workspace, [...evidence_request($this->archive), 'operation' => 'prepare']);
    /** @var list<array{process: resource, prefix: string, boundary: string}> $children */
    $children = [];
    try {
        $commit = ['operation' => 'commit', 'transaction_id' => 'evidence'];
        $a = Probe::start($workspace, $commit, pause: 'authority-held');
        $children[] = $a;
        evidence_paused($a, 'authority-held');
        // File age is deliberately stale while its actual kernel owner remains alive.
        touch($workspace . '/vault/extensions/stashd-transactions/authority.lock', 1);
        $before = Probe::snapshot($workspace . '/vault');
        $b = Probe::start($workspace, $commit, pause: 'authority-contended');
        $children[] = $b;
        evidence_paused($b, 'authority-contended');
        expect(Probe::snapshot($workspace . '/vault'))->toBe($before);
        expect(file_get_contents($b['prefix'] . '.events'))->not->toContain('authority-held');
        // Let the denied claimant wait while the original owner is still paused.
        file_put_contents($b['prefix'] . '.resume', 'resume');
        expect(proc_get_status($a['process'])['running'])->toBeTrue();
        expect(proc_get_status($b['process'])['running'])->toBeTrue();
        expect(file_get_contents($b['prefix'] . '.out'))->toBe('');
        file_put_contents($a['prefix'] . '.resume', 'resume');
        $first = Probe::finish($a);
        $second = Probe::finish($b);
        $children = [];
        expect($first['exit'])->toBe(0);
        expect($second['exit'])->toBe(0);
        /** @var array{item: array{object_id: string, version: string}} $first */
        $first = json_decode($first['out'], true, flags: JSON_THROW_ON_ERROR);
        /** @var array{status: string, transaction_id: string, item: array{object_id: string, version: string}} $second */
        $second = json_decode($second['out'], true, flags: JSON_THROW_ON_ERROR);
        expect($second['item'])->toBe($first['item']);
        Probe::committed($workspace . '/vault', $second, 'evidence', ['master' => 'accepted bytes']);
    } finally {
        PreparationProbe::cancel($children);
    }
});

test('sealed numeric metadata keeps its exact JSON types through plan persistence and replay', function (): void {
    $workspace = evidence_workspace($this->archive);
    $request = evidence_request($this->archive);
    $request['metadata'] = ['measurement' => 1.0, 'zero' => 0, 'nested' => ['value' => 2.0]];
    // The frozen process harness normalizes request floats before Core sees them.
    $adapter = require dirname(__DIR__, 2) . '/Support/RecordsAndArchives/transaction-adapter.php';
    if (! is_callable($adapter)) {
        throw new RuntimeException('Transaction adapter is not callable.');
    }
    $previous = \Tempest\Container\GenericContainer::instance();
    try {
        /** @var array{status: string, item: array{object_id: string, version: string}} $receipt */
        $receipt = $adapter([...$request, 'root' => $workspace . '/vault'], static function (string $name): void {});
    } finally {
        \Tempest\Container\GenericContainer::setInstance($previous);
    }
    expect($receipt['status'])->toBe('complete');
    $item = Probe::json(OcflInspector::payloadPath($workspace . '/vault', $receipt['item']['object_id'], $receipt['item']['version'], 'item.json'));
    expect($item['metadata'])->toBe($request['metadata']);
    evidence_bindings($workspace . '/vault', $workspace . '/vault/extensions/stashd-transactions/' . hash('sha256', 'evidence') . '/candidate.json');
    $before = Probe::snapshot($workspace . '/vault');
    expect(Probe::run($workspace, ['operation' => 'recover', 'transaction_id' => 'evidence'])['item'])->toBe($receipt['item']);
    expect(Probe::snapshot($workspace . '/vault'))->toBe($before);
});

test('discovery contains a damaged bucket without hiding another sealed candidate', function (): void {
    $workspace = evidence_workspace($this->archive);
    $bad = Probe::run($workspace, [...evidence_request($this->archive, 'bad'), 'operation' => 'prepare']);
    Probe::run($workspace, [...evidence_request($this->archive, 'healthy'), 'operation' => 'prepare']);
    unlink($bad['candidate_path']);
    $before = Probe::snapshot($workspace . '/vault');
    $discovered = Probe::run($workspace, ['operation' => 'discover']);
    expect($discovered['ids'])->toBe(['healthy']);
    expect($discovered['quarantined'])->toHaveCount(1);
    expect(Probe::snapshot($workspace . '/vault'))->toBe($before);
    expect(Probe::run($workspace, ['operation' => 'recover', 'transaction_id' => 'healthy'])['status'])->toBe('complete');
});

test('discovery contains damaged present journals while another candidate remains recoverable', function (string $damage): void {
    $workspace = evidence_workspace($this->archive);
    $bad = Probe::run($workspace, [...evidence_request($this->archive, 'bad'), 'operation' => 'prepare']);
    Probe::run($workspace, ['operation' => 'commit', 'transaction_id' => 'bad']);
    Probe::run($workspace, [...evidence_request($this->archive, 'healthy'), 'operation' => 'prepare']);
    $directory = dirname($bad['candidate_path']);
    if (str_starts_with($damage, 'release')) {
        expect(Probe::run($workspace, ['operation' => 'custody.release', 'transaction_id' => 'bad'])['status'])->toBe('released');
    }
    $name = match (explode('-', $damage)[0]) {
        'completion' => 'complete',
        'release' => 'custody-release',
        default => 'plan',
    };
    $path = $directory . '/' . $name . '.json';
    if ($damage === 'completion-without-plan') {
        unlink($directory . '/plan.json');
    } elseif (str_ends_with($damage, 'malformed')) {
        file_put_contents($path, '{');
    } elseif (str_ends_with($damage, 'checksum')) {
        $envelope = Probe::json($path);
        $envelope['sha512'] = str_repeat('0', 128);
        file_put_contents($path, json_encode($envelope, JSON_THROW_ON_ERROR));
    } else {
        $envelope = Probe::json($path);
        /** @var array{candidate?: string, transaction_id?: string, item?: array{object_id: string, version: string}} $record */
        $record = json_decode($envelope['json'], true, flags: JSON_THROW_ON_ERROR);
        if (in_array($damage, ['plan-linkage', 'release-linkage'], true)) {
            $record['candidate'] = str_repeat('0', 128);
        } elseif ($damage === 'plan-item') {
            $record['item'] = ['object_id' => 'urn:example:unrelated', 'version' => 'v99'];
        } elseif ($damage === 'completion-identity') {
            $record['transaction_id'] = 'unrelated';
        } else {
            $record['item'] = ['object_id' => 'urn:example:unrelated', 'version' => 'v99'];
        }
        $json = json_encode($record, JSON_THROW_ON_ERROR);
        file_put_contents($path, json_encode(['json' => $json, 'sha512' => hash('sha512', $json)], JSON_THROW_ON_ERROR));
    }
    $before = Probe::snapshot($workspace . '/vault');
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $discovered = Probe::run($workspace, ['operation' => 'discover']);
        expect($discovered['ids'])->toBe(['healthy']);
        expect($discovered['quarantined'])->toHaveCount(1);
        /** @var list<array{path: string, reason: string}> $quarantined */
        $quarantined = $discovered['quarantined'];
        expect($quarantined[0]['path'])->toBe($directory);
        expect($quarantined[0]['reason'])->not->toBe('');
        expect(Probe::snapshot($workspace . '/vault'))->toBe($before);
    }
    expect(Probe::run($workspace, ['operation' => 'recover', 'transaction_id' => 'healthy'])['status'])->toBe('complete');
})->with([
    'plan-malformed',
    'plan-checksum',
    'plan-linkage',
    'plan-item',
    'completion-malformed',
    'completion-checksum',
    'completion-identity',
    'completion-item',
    'completion-without-plan',
    'release-malformed',
    'release-linkage',
]);
