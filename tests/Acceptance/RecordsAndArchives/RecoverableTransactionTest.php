<?php

use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\RecordsAndArchives\ArchiveFixture;
use Tests\Support\RecordsAndArchives\OcflInspector;
use Tests\Support\RecordsAndArchives\ProjectionProbe;
use Tests\Support\RecordsAndArchives\TransactionProbe as Probe;

/**
 * Prepare one test-only request describing a delta for the real application adapter.
 *
 * These names are harness vocabulary, not a proposed production API or journal format.
 */
function transaction_request(ArchiveFixture $fixture, string $id = 'tx-a', array $bytes = ['master' => 'master bytes']): array
{
    $changes = [];
    foreach ($bytes as $key => $value) {
        $changes[$key] = $fixture->input($id . '-' . $key . '.bin', $value);
    }

    return [
        'operation' => 'prepare',
        'transaction_id' => $id,
        'uri' => 'urn:example:transaction:item',
        'changes' => $changes,
        'metadata' => ['event' => $id],
        'preconditions' => [],
    ];
}

/**
 * Return the disposable workspace and initialize its actual OCFL Vault.
 */
function transaction_workspace(ArchiveFixture $fixture): string
{
    $fixture->run('vault.initialize');

    return dirname($fixture->path('vault'));
}

/**
 * Check that every previously observed physical file survives unchanged.
 *
 * Root inventories may advance; callers pass only immutable history when needed.
 *
 * @param array<string, string> $before
 * @param array<string, string> $after
 */
function transaction_retains(array $before, array $after): void
{
    foreach ($before as $path => $digest) {
        expect($after[$path] ?? null)->toBe($digest, $path);
    }
}

test('PSC-T01 existing services preserve an unlinked Asset after real process death', function (): void {
    $workspace = transaction_workspace($this->archive);
    $request = transaction_request($this->archive);
    $request['operation'] = 'legacy.publish';
    Probe::finish(Probe::start($workspace, $request, 'asset-verified:master'));
    $objects = $this->archive->run('vault.list_objects')['object_ids'];
    expect($objects)->toHaveCount(1);
    $found = OcflInspector::inventoryFor($workspace . '/vault', $objects[0]);
    expect($found['inventory']['head'])->toBe('v1');
    OcflInspector::assertAcknowledgedHistory($workspace . '/vault', $objects[0], 'v1');
    expect(file_get_contents(OcflInspector::payloadPath($workspace . '/vault', $objects[0], 'v1', 'data.bin')))->toBe('master bytes');
});

test('PSC-T02 crash windows recover once and retain exact authoritative relationships', function (string $boundary): void {
    $workspace = transaction_workspace($this->archive);
    $request = transaction_request($this->archive, bytes: ['master' => 'master bytes', 'sidecar' => 'sidecar bytes']);
    // Seal is itself part of this process, so before/after sealing are genuine kill points.
    Probe::finish(Probe::start($workspace, [...$request, 'operation' => 'execute'], $boundary));
    $root = $workspace . '/vault';
    $before = Probe::snapshot($root);
    $objects = $this->archive->run('vault.list_objects')['object_ids'];
    $itemIds = array_values(array_filter($objects, static fn (string $id): bool => str_starts_with($id, 'urn:stashd:item:')));
    if (in_array($boundary, ['before-seal', 'after-seal', 'before-asset:master'], true)) {
        expect($objects)->toBe([]);
    }

    if (in_array($boundary, ['asset-verified:master', 'before-asset:sidecar', 'asset-verified:sidecar', 'before-item'], true)) {
        expect($itemIds)->toBe([]);
        $observed = [];
        foreach ($objects as $id) {
            OcflInspector::assertAcknowledgedHistory($root, $id, 'v1');
            $record = Probe::json(OcflInspector::payloadPath($root, $id, 'v1', '.stashd/asset.json'));
            $observed[$record['asset_key']] = file_get_contents(OcflInspector::payloadPath($root, $id, 'v1', 'data.bin'));
        }

        ksort($observed);
        expect($observed)->toBe(
            in_array($boundary, ['asset-verified:master', 'before-asset:sidecar'], true)
                ? ['master' => 'master bytes']
                : ['master' => 'master bytes', 'sidecar' => 'sidecar bytes'],
        );
    }

    if (in_array($boundary, ['item-published', 'item-verified', 'before-ack', 'after-ack'], true)) {
        expect($itemIds)->toHaveCount(1);
        OcflInspector::assertAcknowledgedHistory($root, $itemIds[0], 'v1');
        $record = Probe::json(OcflInspector::payloadPath($root, $itemIds[0], 'v1', 'item.json'));
        expect($record['pins'])->toHaveCount(2);
        foreach ($record['pins'] as $pin) {
            expect($pin['version'])->toBe('v1');
            OcflInspector::assertAcknowledgedHistory($root, $pin['object_id'], 'v1');
        }
    }

    $recovery = ['operation' => 'recover', 'transaction_id' => 'tx-a'];
    if ($boundary === 'before-seal') {
        $outcome = Probe::run($workspace, $recovery);
        expect($outcome['status'])->toBeIn(['absent', 'unsealed', 'quarantined']);
        expect(Probe::snapshot($root))->toBe($before);

        return;
    }

    // A sealed candidate must own its material; acquisition source paths are gone.
    $this->archive->destroy('inputs');
    $receipt = Probe::run($workspace, $recovery);
    Probe::committed($root, $receipt, 'tx-a', ['master' => 'master bytes', 'sidecar' => 'sidecar bytes']);
    $immutable = array_filter($before, static fn (string $path): bool => (bool) preg_match('~/v\d+/~', $path), ARRAY_FILTER_USE_KEY);
    transaction_retains($immutable, Probe::snapshot($root));
    $settled = Probe::snapshot($root);
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $again = Probe::run($workspace, $recovery);
        expect($again['item'])->toBe($receipt['item']);
        Probe::committed($root, $again, 'tx-a', ['master' => 'master bytes', 'sidecar' => 'sidecar bytes']);
        expect(Probe::snapshot($root))->toBe($settled);
    }
})->with([
    'before-seal',
    'after-seal',
    'before-asset:master',
    'asset-verified:master',
    'before-asset:sidecar',
    'asset-verified:sidecar',
    'before-item',
    'item-published',
    'item-verified',
    'before-ack',
    'after-ack',
]);

test('PSC-T03 duplicate dispatch produces one Item and one revision per changed Asset', function (): void {
    $workspace = transaction_workspace($this->archive);
    $request = transaction_request($this->archive);
    Probe::run($workspace, $request);
    $commit = ['operation' => 'commit', 'transaction_id' => 'tx-a'];
    $receipt = Probe::run($workspace, $commit);
    Probe::committed($workspace . '/vault', $receipt, 'tx-a', ['master' => 'master bytes']);
    expect($receipt['item']['version'])->toBe('v1');
    $before = Probe::snapshot($workspace . '/vault');
    expect(Probe::run($workspace, $commit)['item'])->toBe($receipt['item']);
    expect(Probe::snapshot($workspace . '/vault'))->toBe($before);
});

test('PSC-T04 stale disjoint deltas preserve newer relationships or explicitly reject', function (): void {
    $workspace = transaction_workspace($this->archive);
    Probe::run($workspace, transaction_request($this->archive, 'old', ['old' => 'old bytes']));
    Probe::run($workspace, transaction_request($this->archive, 'new', ['new' => 'new bytes']));
    $new = Probe::run($workspace, ['operation' => 'commit', 'transaction_id' => 'new']);
    Probe::committed($workspace . '/vault', $new, 'new', ['new' => 'new bytes']);
    $before = Probe::snapshot($workspace . '/vault');
    $old = Probe::run($workspace, ['operation' => 'commit', 'transaction_id' => 'old']);
    if ($old['status'] === 'complete') {
        Probe::committed($workspace . '/vault', $old, 'old', ['old' => 'old bytes', 'new' => 'new bytes']);
        $history = array_filter($before, static fn (string $path): bool => (bool) preg_match('~/v\d+/~', $path), ARRAY_FILTER_USE_KEY);
        transaction_retains($history, Probe::snapshot($workspace . '/vault'));
    } else {
        expect($old['status'])->toBeIn(['conflict', 'quarantined']);
        expect(Probe::snapshot($workspace . '/vault'))->toBe($before);
    }
});

test('PSC-T05 two concurrent dispatchers cannot both publish the same candidate', function (bool $killOwner): void {
    $workspace = transaction_workspace($this->archive);
    Probe::run($workspace, transaction_request($this->archive));
    $commit = ['operation' => 'commit', 'transaction_id' => 'tx-a'];
    $results = Probe::contend($workspace, $commit, $commit, $killOwner);
    foreach ($results as $index => $outcome) {
        expect($outcome['status'])->toBeIn($index === 0 && $killOwner ? ['owner-killed'] : ['complete', 'busy']);
    }

    $receipt = Probe::run($workspace, ['operation' => 'recover', 'transaction_id' => 'tx-a']);
    Probe::committed($workspace . '/vault', $receipt, 'tx-a', ['master' => 'master bytes']);
    expect($receipt['item']['version'])->toBe('v1');
    foreach ($this->archive->run('vault.list_objects')['object_ids'] as $id) {
        expect(array_keys(OcflInspector::inventoryFor($workspace . '/vault', $id)['inventory']['versions']))->toBe(['v1']);
    }
})->with([false, true]);

test('PSC-T06 same identity cannot be resealed with different content', function (): void {
    $workspace = transaction_workspace($this->archive);
    Probe::run($workspace, transaction_request($this->archive));
    $before = Probe::snapshot($workspace . '/vault');
    $outcome = Probe::run($workspace, transaction_request($this->archive, bytes: ['master' => 'conflicting bytes']));
    expect($outcome['status'])->toBeIn(['conflict', 'rejected', 'quarantined']);
    expect(Probe::snapshot($workspace . '/vault'))->toBe($before);
});

test('PSC-T07 damaged sealed evidence is contained without publication or deletion', function (string $damage): void {
    $workspace = transaction_workspace($this->archive);
    $prepared = Probe::run($workspace, transaction_request($this->archive));
    $path = $prepared[$damage === 'manifest' ? 'candidate_path' : 'custody_path'];
    expect(realpath($path))->toStartWith($workspace . '/');
    file_put_contents($path, 'damaged evidence');
    $before = Probe::snapshot($workspace . '/vault');
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $outcome = Probe::run($workspace, ['operation' => 'recover', 'transaction_id' => 'tx-a']);
        expect($outcome['status'])->toBeIn(['rejected', 'quarantined']);
        expect(file_get_contents($path))->toBe('damaged evidence');
        expect(Probe::snapshot($workspace . '/vault'))->toBe($before);
        expect($this->archive->run('vault.list_objects')['object_ids'])->toBe([]);
    }
})->with(['manifest', 'custody']);

test('PSC-T08 metadata only commits retain existing exact pins without new Asset revisions', function (): void {
    $workspace = transaction_workspace($this->archive);
    $initial = transaction_request($this->archive);
    $receipt = Probe::run($workspace, [...$initial, 'operation' => 'execute']);
    Probe::committed($workspace . '/vault', $receipt, 'tx-a', ['master' => 'master bytes']);
    $before = Probe::snapshot($workspace . '/vault');
    $next = Probe::run($workspace, [...transaction_request($this->archive, 'metadata', []), 'operation' => 'execute']);
    Probe::committed($workspace . '/vault', $next, 'metadata', ['master' => 'master bytes']);
    expect($next['item']['version'])->toBe('v2');
    $record = json_decode(file_get_contents(OcflInspector::payloadPath($workspace . '/vault', $next['item']['object_id'], 'v2', 'item.json')), true, flags: JSON_THROW_ON_ERROR);
    expect($record['metadata'])->toBe(['event' => 'metadata']);
    $oldFiles = array_filter($before, static fn (string $path): bool => (bool) preg_match('~/v\d+/~', $path), ARRAY_FILTER_USE_KEY);
    transaction_retains($oldFiles, Probe::snapshot($workspace . '/vault'));
    foreach ($this->archive->run('vault.list_objects')['object_ids'] as $id) {
        if (! str_starts_with($id, 'urn:stashd:asset:')) {
            continue;
        }

        expect(array_keys(OcflInspector::inventoryFor($workspace . '/vault', $id)['inventory']['versions']))->toBe(['v1']);
    }
});

test('PSC-T09 Vault only recovery survives actual removal of disposable projections', function (string $boundary): void {
    $workspace = transaction_workspace($this->archive);
    Probe::run($workspace, transaction_request($this->archive));
    Probe::finish(Probe::start($workspace, ['operation' => 'commit', 'transaction_id' => 'tx-a'], $boundary));
    // Adapter must create a real isolated projection here, then physically drop it.
    $name = 'stashd_tx_test_' . bin2hex(random_bytes(8));
    $fixture = ['database' => $name, 'username' => $name, 'schema' => $name];
    $projectionCreated = false;
    $database = null;
    try {
        $projection = Probe::run($workspace, ['operation' => 'projection.describe', 'fixture' => $fixture]);
        $projectionCreated = true;
        $database = ProjectionProbe::connect($projection, $name);
        $exists = $database->prepare('SELECT count(*) FROM pg_namespace WHERE nspname = ?');
        $exists->execute([$projection['schema']]);
        expect((int) $exists->fetchColumn())->toBe(1);
        $dropped = Probe::run($workspace, ['operation' => 'projection.destroy', 'fixture' => $fixture]);
        expect($dropped['verified_absent'])->toBeTrue();
        $exists->execute([$projection['schema']]);
        expect((int) $exists->fetchColumn())->toBe(0);
        $this->archive->destroy('inputs');
        $receipt = Probe::run($workspace, ['operation' => 'recover', 'transaction_id' => 'tx-a']);
        Probe::committed($workspace . '/vault', $receipt, 'tx-a', ['master' => 'master bytes']);
        $before = Probe::snapshot($workspace . '/vault');
        $rebuilt = Probe::run($workspace, ['operation' => 'projection.rebuild', 'fixture' => $fixture]);
        expect($rebuilt['items'])->toContain($receipt['item']);
        $record = Probe::json(OcflInspector::payloadPath($workspace . '/vault', $receipt['item']['object_id'], $receipt['item']['version'], 'item.json'));
        ProjectionProbe::assertRebuilt($database, $projection, $receipt['item'], $record['pins']);
        $exists->execute([$projection['schema']]);
        expect((int) $exists->fetchColumn())->toBe(1);
        expect(Probe::snapshot($workspace . '/vault'))->toBe($before);
    } finally {
        try {
            // Never issue SQL against an unvalidated PDO connection.
            if ($database instanceof PDO) {
                $database->exec('DROP SCHEMA IF EXISTS "' . $name . '" CASCADE');
            }
        } finally {
            $exists = null;
            $database = null;
            // Describe may have provisioned a role/database even if connection
            // validation failed; dispose must be scoped to the parent-generated IDs.
            if ($projectionCreated) {
                Probe::run($workspace, ['operation' => 'projection.dispose', 'fixture' => $fixture]);
            }
        }
    }
})->with(['asset-verified:master', 'item-verified', 'after-ack']);

test('PSC-T10 independent oracle detects false pins, lost relationships and corrupt bytes', function (string $mutant): void {
    $workspace = transaction_workspace($this->archive);
    $request = transaction_request($this->archive, bytes: ['master' => 'master bytes', 'sidecar' => 'sidecar bytes']);
    $receipt = Probe::run($workspace, [...$request, 'operation' => 'legacy.publish']);
    $receipt += ['status' => 'complete', 'transaction_id' => 'tx-a'];
    Probe::committed($workspace . '/vault', $receipt, 'tx-a', ['master' => 'master bytes', 'sidecar' => 'sidecar bytes']);
    if ($mutant === 'lost-relationship') {
        $request['changes'] = [];
        $request['metadata'] = ['event' => 'stale-snapshot'];
        $receipt = Probe::run($workspace, [...$request, 'operation' => 'legacy.publish']) + ['status' => 'complete', 'transaction_id' => 'tx-a'];
    } elseif ($mutant === 'false-receipt') {
        $receipt['item']['version'] = 'v99';
    } else {
        $id = $this->archive->run('vault.list_objects')['object_ids'];
        $id = array_values(array_filter($id, static fn (string $id): bool => str_starts_with($id, 'urn:stashd:asset:')))[0];
        file_put_contents(OcflInspector::payloadPath($workspace . '/vault', $id, 'v1', 'data.bin'), 'corrupt bytes');
    }

    expect(fn () => Probe::committed($workspace . '/vault', $receipt, 'tx-a', ['master' => 'master bytes', 'sidecar' => 'sidecar bytes']))->toThrow(AssertionFailedError::class);
})->with(['lost-relationship', 'false-receipt', 'corrupt-bytes']);

test('PSC-T11 a stale conflicting precondition cannot replace a newer exact pin', function (): void {
    $workspace = transaction_workspace($this->archive);
    $first = Probe::run($workspace, [...transaction_request($this->archive), 'operation' => 'execute']);
    $pin = json_decode(
        file_get_contents(OcflInspector::payloadPath($workspace . '/vault', $first['item']['object_id'], 'v1', 'item.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    )['pins'][0];
    $old = transaction_request($this->archive, 'old', ['master' => 'stale bytes']);
    $old['preconditions'] = ['pins' => [$pin]];
    Probe::run($workspace, $old);
    $new = Probe::run($workspace, [...transaction_request($this->archive, 'new', ['master' => 'new bytes']), 'operation' => 'execute']);
    Probe::committed($workspace . '/vault', $new, 'new', ['master' => 'new bytes']);
    $before = Probe::snapshot($workspace . '/vault');
    $rejected = Probe::run($workspace, ['operation' => 'commit', 'transaction_id' => 'old']);
    expect($rejected['status'])->toBeIn(['conflict', 'quarantined']);
    expect(Probe::snapshot($workspace . '/vault'))->toBe($before);
    Probe::committed($workspace . '/vault', $new, 'new', ['master' => 'new bytes']);
});

test('PSC-T12 invalid deltas never publish an Object', function (string $invalid): void {
    $workspace = transaction_workspace($this->archive);
    $request = transaction_request($this->archive);
    if ($invalid === 'path') {
        $request['changes'] = ['../escape' => reset($request['changes'])];
    } elseif ($invalid === 'source') {
        $request['changes']['master'] = $workspace . '/missing';
    } else {
        $request['uri'] = '../invalid-item';
    }

    $result = Probe::run($workspace, [...$request, 'operation' => 'execute']);
    expect($result['status'])->toBeIn(['rejected', 'quarantined']);
    expect($this->archive->run('vault.list_objects')['object_ids'])->toBe([]);
})->with(['path', 'source', 'identity']);

test('PSC-T13 contradictory committed evidence cannot be acknowledged or erased by recovery', function (string $damage): void {
    $workspace = transaction_workspace($this->archive);
    $receipt = Probe::run($workspace, [...transaction_request($this->archive), 'operation' => 'execute']);
    Probe::committed($workspace . '/vault', $receipt, 'tx-a', ['master' => 'master bytes']);
    $item = $receipt['item'];
    $found = OcflInspector::inventoryFor($workspace . '/vault', $item['object_id']);
    $path = match ($damage) {
        'sidecar' => $found['path'] . '.sha512',
        'inventory' => dirname($found['path']) . '/v1/inventory.json',
        'payload' => OcflInspector::payloadPath($workspace . '/vault', $item['object_id'], 'v1', 'item.json'),
    };
    file_put_contents($path, 'contradictory evidence');
    $before = Probe::snapshot($workspace . '/vault');
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $result = Probe::run($workspace, ['operation' => 'recover', 'transaction_id' => 'tx-a']);
        expect($result['status'])->toBeIn(['quarantined', 'degraded']);
        expect(Probe::snapshot($workspace . '/vault'))->toBe($before);
    }
})->with(['sidecar', 'inventory', 'payload']);

test('PSC-T14 zero Asset transaction publishes one metadata only Item', function (): void {
    $workspace = transaction_workspace($this->archive);
    $receipt = Probe::run($workspace, [...transaction_request($this->archive, bytes: []), 'operation' => 'execute']);
    Probe::committed($workspace . '/vault', $receipt, 'tx-a', []);
    expect($receipt['item']['version'])->toBe('v1');
    expect($this->archive->run('vault.list_objects')['object_ids'])->toBe([$receipt['item']['object_id']]);
});

test('PSC-T15 stable sealed identity binds custody bytes rather than mutable producer paths', function (): void {
    $workspace = transaction_workspace($this->archive);
    $request = transaction_request($this->archive);
    $prepared = Probe::run($workspace, $request);
    expect($prepared['transaction_id'])->toBe('tx-a');
    $manifest = file_get_contents($prepared['candidate_path']);
    expect(hash('sha512', $manifest))->toBe($prepared['candidate_sha512']);
    expect(Probe::run($workspace, $request))->toBe($prepared);
    file_put_contents($request['changes']['master'], 'producer changed bytes');
    $receipt = Probe::run($workspace, ['operation' => 'commit', 'transaction_id' => 'tx-a']);
    Probe::committed($workspace . '/vault', $receipt, 'tx-a', ['master' => 'master bytes']);
    expect(file_get_contents($prepared['candidate_path']))->toBe($manifest);
});

test('PSC-T16 projection failure cannot undo authoritative completion or duplicate publication', function (): void {
    $workspace = transaction_workspace($this->archive);
    $receipt = Probe::run($workspace, [...transaction_request($this->archive), 'operation' => 'execute']);
    // Every child has unreachable database configuration, not a simulated success flag.
    Probe::committed($workspace . '/vault', $receipt, 'tx-a', ['master' => 'master bytes']);
    $before = Probe::snapshot($workspace . '/vault');
    $again = Probe::run($workspace, ['operation' => 'recover', 'transaction_id' => 'tx-a']);
    expect($again['item'])->toBe($receipt['item']);
    expect(Probe::snapshot($workspace . '/vault'))->toBe($before);
});

test('PSC-T17 different concurrent candidates preserve every acknowledged relationship', function (bool $killOwner): void {
    $workspace = transaction_workspace($this->archive);
    Probe::run($workspace, transaction_request($this->archive, 'a', ['a' => 'A bytes']));
    Probe::run($workspace, transaction_request($this->archive, 'b', ['b' => 'B bytes']));
    $outcomes = Probe::contend(
        $workspace,
        ['operation' => 'commit', 'transaction_id' => 'a'],
        ['operation' => 'commit', 'transaction_id' => 'b'],
        $killOwner,
    );
    $committed = [];
    foreach ($outcomes as $index => $outcome) {
        if (in_array($outcome['status'], ['busy', 'owner-killed'], true)) {
            $outcome = Probe::run($workspace, ['operation' => 'recover', 'transaction_id' => $index === 0 ? 'a' : 'b']);
        }

        expect($outcome['status'])->toBeIn(['complete', 'conflict', 'quarantined']);
        if ($outcome['status'] === 'complete') {
            $committed[$index === 0 ? 'a' : 'b'] = $outcome;
        }
    }

    expect($committed)->not->toBeEmpty();
    $last = array_reduce($committed, static fn (?array $latest, array $next): array => $latest === null
        || (int) substr($next['item']['version'], 1) > (int) substr($latest['item']['version'], 1)
            ? $next
            : $latest);
    $expected = [];
    foreach ($committed as $key => $outcome) {
        $expected[$key] = $key === 'a' ? 'A bytes' : 'B bytes';
        $inventory = OcflInspector::inventoryFor($workspace . '/vault', $outcome['item']['object_id'])['inventory'];
        expect(isset($inventory['versions'][$outcome['item']['version']]))->toBeTrue();
    }

    Probe::committed($workspace . '/vault', $last, $last['transaction_id'], $expected);
})->with([false, true]);

test('PSC-T18 an unknown transaction cannot invent history from unrelated committed Objects', function (): void {
    $workspace = transaction_workspace($this->archive);
    $receipt = Probe::run($workspace, [...transaction_request($this->archive), 'operation' => 'execute']);
    Probe::committed($workspace . '/vault', $receipt, 'tx-a', ['master' => 'master bytes']);
    $before = Probe::snapshot($workspace . '/vault');
    $unknown = Probe::run($workspace, ['operation' => 'recover', 'transaction_id' => 'never-sealed']);
    expect($unknown['status'])->toBeIn(['absent', 'rejected', 'quarantined']);
    expect(Probe::snapshot($workspace . '/vault'))->toBe($before);
});

test('PSC-T19 independent snapshot witness detects duplicate archival publication', function (): void {
    $workspace = transaction_workspace($this->archive);
    $request = transaction_request($this->archive, bytes: []);
    Probe::run($workspace, [...$request, 'operation' => 'legacy.publish']);
    $before = Probe::snapshot($workspace . '/vault');
    $request['metadata'] = ['event' => 'duplicate tx-a'];
    Probe::run($workspace, [...$request, 'operation' => 'legacy.publish']);
    expect(fn () => expect(Probe::snapshot($workspace . '/vault'))->toBe($before))->toThrow(AssertionFailedError::class);
});

test('PSC-T20 authority supervisor observes real contention and detects a bypassed lock', function (bool $killOwner, bool $bypass): void {
    $workspace = transaction_workspace($this->archive);
    $run = fn (): array => Probe::contend($workspace, ['operation' => 'probe.ownership'], ['operation' => 'probe.ownership', 'bypass_lock' => $bypass], $killOwner);
    if ($bypass) {
        expect($run)->toThrow(AssertionFailedError::class, 'Child exited before authority-contended');

        return;
    }

    $results = $run();
    expect($results[0]['status'])->toBe($killOwner ? 'owner-killed' : 'probe-complete');
    expect($results[1]['status'])->toBe('probe-complete');
})->with([[false, false], [true, false], [false, true]]);

test('PSC-T21 destructive projection connections reject wrong database role host and schema before connecting', function (string $field): void {
    $name = 'stashd_tx_test_0123456789abcdef';
    $projection = [
        'dsn' => 'pgsql:host=lerd-postgres;port=5432;dbname=' . $name,
        'username' => $name,
        'schema' => $name,
        'password' => bin2hex(random_bytes(16)),
    ];
    if ($field === 'database') {
        $projection['dsn'] = 'pgsql:host=lerd-postgres;port=5432;dbname=core';
    } elseif ($field === 'host') {
        $projection['dsn'] = 'pgsql:host=elsewhere;port=5432;dbname=' . $name;
    } else {
        $projection[$field] = 'postgres';
    }

    expect(fn () => ProjectionProbe::connect($projection, $name))->toThrow(AssertionFailedError::class);
})->with(['database', 'username', 'host', 'schema']);

test('PSC-T22 prohibited writes include additional OCFL files with all earlier bytes unchanged', function (): void {
    $workspace = transaction_workspace($this->archive);
    $request = transaction_request($this->archive, bytes: []);
    $receipt = Probe::run($workspace, [...$request, 'operation' => 'legacy.publish']);
    $object = dirname(OcflInspector::inventoryFor($workspace . '/vault', $receipt['item']['object_id'])['path']);
    $before = Probe::snapshot($workspace . '/vault');
    mkdir($object . '/v2');
    file_put_contents($object . '/v2/inventory.json', 'unauthorised new revision evidence');
    $after = Probe::snapshot($workspace . '/vault');
    transaction_retains($before, $after);
    expect(fn () => expect($after)->toBe($before))->toThrow(AssertionFailedError::class);
});
