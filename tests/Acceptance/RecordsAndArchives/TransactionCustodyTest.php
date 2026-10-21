<?php

use Tests\Support\RecordsAndArchives\ArchiveFixture;
use Tests\Support\RecordsAndArchives\OcflInspector;
use Tests\Support\RecordsAndArchives\TransactionProbe as Probe;

/**
 * Initialize a disposable two-Asset candidate and expose its physical custody bucket.
 *
 * @return array{workspace: string, directory: string}
 */
function custody_workspace(ArchiveFixture $archive): array
{
    $archive->run('vault.initialize');
    $workspace = dirname($archive->path('vault'));
    $prepared = Probe::run($workspace, [
        'operation' => 'prepare',
        'transaction_id' => 'custody',
        'uri' => 'urn:example:transaction:item',
        'changes' => ['master' => $archive->input('master.bin', 'master bytes'), 'sidecar' => $archive->input('sidecar.bin', 'sidecar bytes')],
        'metadata' => ['event' => 'custody'],
        'preconditions' => [],
    ]);
    return ['workspace' => $workspace, 'directory' => dirname($prepared['candidate_path'])];
}

/**
 * Independently verify the exact acknowledged Item and its pins in physical OCFL.
 *
 * @param array{status: string, transaction_id: string, item: array{object_id: string, version: string}} $receipt
 */
function custody_committed(string $workspace, array $receipt): void
{
    Probe::committed($workspace . '/vault', $receipt, 'custody', ['master' => 'master bytes', 'sidecar' => 'sidecar bytes']);
}

test('completed custody release retains permanent evidence and replays exact history in fresh processes', function (): void {
    ['workspace' => $workspace, 'directory' => $directory] = custody_workspace($this->archive);
    /** @var array{status: string, transaction_id: string, item: array{object_id: string, version: string}} $receipt */
    $receipt = Probe::run($workspace, ['operation' => 'commit', 'transaction_id' => 'custody']);
    custody_committed($workspace, $receipt);
    $before = Probe::snapshot($workspace . '/vault');
    expect(file_get_contents($directory . '/custody-0'))->toBe('master bytes');
    expect(file_get_contents($directory . '/custody-1'))->toBe('sidecar bytes');
    /** @var ArchiveFixture $archive */
    $archive = $this->archive;
    $archive->destroy('inputs');
    $released = Probe::run($workspace, ['operation' => 'custody.release', 'transaction_id' => 'custody']);
    expect($released['status'])->toBe('released');
    expect($released['item'])->toBe($receipt['item']);
    expect(file_exists($directory . '/custody-0'))->toBeFalse();
    expect(file_exists($directory . '/custody-1'))->toBeFalse();
    $after = Probe::snapshot($workspace . '/vault');
    $removed = array_keys(array_diff_key($before, $after));
    sort($removed);
    $prefix = '/extensions/stashd-transactions/' . basename($directory);
    expect($removed)->toBe([$prefix . '/custody-0', $prefix . '/custody-1']);
    expect(array_keys(array_diff_key($after, $before)))->toBe([$prefix . '/custody-release.json']);
    foreach (array_intersect_key($before, $after) as $path => $digest) {
        expect($after[$path])->toBe($digest);
    }
    $authorization = Probe::json($directory . '/custody-release.json');
    expect(hash('sha512', $authorization['json']))->toBe($authorization['sha512']);
    /** @var array{completion: array, candidate: string} $authorization */
    $authorization = json_decode($authorization['json'], true, flags: JSON_THROW_ON_ERROR);
    expect($authorization['completion'])->toBe($receipt);
    expect($authorization['candidate'])->toBe(file_get_contents($directory . '/candidate.sha512'));
    expect(Probe::run($workspace, ['operation' => 'discover'])['ids'])->toBe(['custody']);
    foreach (['commit', 'recover', 'custody.release', 'recover'] as $operation) {
        $again = Probe::run($workspace, ['operation' => $operation, 'transaction_id' => 'custody']);
        expect($again['status'])->toBe($operation === 'custody.release' ? 'released' : 'complete');
        expect($again['item'])->toBe($receipt['item']);
        if ($operation !== 'custody.release') {
            /** @var array{status: string, transaction_id: string, item: array{object_id: string, version: string}} $again */
            custody_committed($workspace, $again);
        }
        expect(Probe::snapshot($workspace . '/vault'))->toBe($after);
    }
});

test('unfinished custody cannot be released or advanced by cleanup', function (string $boundary): void {
    ['workspace' => $workspace, 'directory' => $directory] = custody_workspace($this->archive);
    if ($boundary !== 'sealed') {
        Probe::finish(Probe::start($workspace, ['operation' => 'commit', 'transaction_id' => 'custody'], $boundary));
    }
    $before = Probe::snapshot($workspace . '/vault');
    for ($attempt = 0; $attempt < 2; $attempt++) {
        expect(Probe::run($workspace, ['operation' => 'custody.release', 'transaction_id' => 'custody'])['status'])->toBe('not-complete');
        expect(Probe::snapshot($workspace . '/vault'))->toBe($before);
        expect(file_get_contents($directory . '/custody-0'))->toBe('master bytes');
        expect(file_get_contents($directory . '/custody-1'))->toBe('sidecar bytes');
    }
})->with(['sealed', 'asset-verified:master', 'item-published', 'item-verified', 'before-ack']);

test('process death during custody release preserves completed replay and permits idempotent cleanup', function (string $boundary): void {
    ['workspace' => $workspace, 'directory' => $directory] = custody_workspace($this->archive);
    $receipt = Probe::run($workspace, ['operation' => 'commit', 'transaction_id' => 'custody']);
    Probe::finish(Probe::start($workspace, ['operation' => 'custody.release', 'transaction_id' => 'custody'], $boundary));
    $interrupted = Probe::snapshot($workspace . '/vault');
    $expected = match ($boundary) {
        'custody-released:master' => [false, true],
        'custody-released:sidecar' => [false, false],
        default => [true, true],
    };
    expect([file_exists($directory . '/custody-0'), file_exists($directory . '/custody-1')])->toBe($expected);
    foreach (['commit', 'recover'] as $operation) {
        /** @var array{status: string, transaction_id: string, item: array{object_id: string, version: string}} $again */
        $again = Probe::run($workspace, ['operation' => $operation, 'transaction_id' => 'custody']);
        expect($again['status'])->toBe('complete');
        expect($again['item'])->toBe($receipt['item']);
        custody_committed($workspace, $again);
        expect(Probe::snapshot($workspace . '/vault'))->toBe($interrupted);
    }
    expect(Probe::run($workspace, ['operation' => 'custody.release', 'transaction_id' => 'custody'])['status'])->toBe('released');
    expect(file_exists($directory . '/custody-0'))->toBeFalse();
    expect(file_exists($directory . '/custody-1'))->toBeFalse();
    $settled = Probe::snapshot($workspace . '/vault');
    expect(Probe::run($workspace, ['operation' => 'custody.release', 'transaction_id' => 'custody'])['status'])->toBe('released');
    expect(Probe::snapshot($workspace . '/vault'))->toBe($settled);
})->with(['before-custody-release', 'custody-release-authorized', 'custody-released:master', 'custody-released:sidecar']);

test('damaged authoritative or journal evidence prevents custody deletion and reacknowledgement', function (string $damage): void {
    ['workspace' => $workspace, 'directory' => $directory] = custody_workspace($this->archive);
    /** @var array{item: array{object_id: string, version: string}} $receipt */
    $receipt = Probe::run($workspace, ['operation' => 'commit', 'transaction_id' => 'custody']);
    if ($damage === 'asset-after-release') {
        Probe::run($workspace, ['operation' => 'custody.release', 'transaction_id' => 'custody']);
    }
    $item = $receipt['item'];
    /** @var array{pins: list<array{object_id: string, version: string}>} $record */
    $record = Probe::json(OcflInspector::payloadPath($workspace . '/vault', $item['object_id'], $item['version'], 'item.json'));
    $pin = $record['pins'][0];
    $path = match ($damage) {
        'asset', 'asset-after-release' => OcflInspector::payloadPath($workspace . '/vault', $pin['object_id'], $pin['version'], 'data.bin'),
        'item' => OcflInspector::payloadPath($workspace . '/vault', $item['object_id'], $item['version'], 'item.json'),
        'candidate' => $directory . '/candidate.json',
        'plan' => $directory . '/plan.json',
        'completion' => $directory . '/complete.json',
        default => throw new InvalidArgumentException('Unknown custody damage fixture.'),
    };
    file_put_contents($path, 'damaged evidence');
    $before = Probe::snapshot($workspace . '/vault');
    foreach (['custody.release', 'recover'] as $operation) {
        expect(Probe::run($workspace, ['operation' => $operation, 'transaction_id' => 'custody'])['status'])->toBe('quarantined');
        expect(Probe::snapshot($workspace . '/vault'))->toBe($before);
    }
})->with(['asset', 'item', 'candidate', 'plan', 'completion', 'asset-after-release']);

test('forged completion cannot authorize unfinished custody release', function (bool $partial): void {
    ['workspace' => $workspace, 'directory' => $directory] = custody_workspace($this->archive);
    if ($partial) {
        Probe::finish(Probe::start($workspace, ['operation' => 'commit', 'transaction_id' => 'custody'], 'asset-verified:master'));
    }
    $json = json_encode(['status' => 'complete', 'transaction_id' => 'custody', 'item' => ['object_id' => 'urn:example:invented', 'version' => 'v99']], JSON_THROW_ON_ERROR);
    file_put_contents($directory . '/complete.json', json_encode(['json' => $json, 'sha512' => hash('sha512', $json)], JSON_THROW_ON_ERROR));
    $before = Probe::snapshot($workspace . '/vault');
    expect(Probe::run($workspace, ['operation' => 'custody.release', 'transaction_id' => 'custody'])['status'])->toBe('quarantined');
    expect(Probe::snapshot($workspace . '/vault'))->toBe($before);
    expect(file_get_contents($directory . '/custody-0'))->toBe('master bytes');
    expect(file_get_contents($directory . '/custody-1'))->toBe('sidecar bytes');
})->with([false, true]);

test('lost completion reply still permits verified custody release without republishing', function (): void {
    ['workspace' => $workspace] = custody_workspace($this->archive);
    Probe::finish(Probe::start($workspace, ['operation' => 'commit', 'transaction_id' => 'custody'], 'after-ack'));
    $released = Probe::run($workspace, ['operation' => 'custody.release', 'transaction_id' => 'custody']);
    expect($released['status'])->toBe('released');
    $before = Probe::snapshot($workspace . '/vault');
    /** @var array{status: string, transaction_id: string, item: array{object_id: string, version: string}} $receipt */
    $receipt = Probe::run($workspace, ['operation' => 'recover', 'transaction_id' => 'custody']);
    expect($receipt['status'])->toBe('complete');
    expect($receipt['item'])->toBe($released['item']);
    custody_committed($workspace, $receipt);
    expect(Probe::snapshot($workspace . '/vault'))->toBe($before);
});

test('concurrent cleanup reuses one release authorization under actual publication authority', function (): void {
    ['workspace' => $workspace] = custody_workspace($this->archive);
    $receipt = Probe::run($workspace, ['operation' => 'commit', 'transaction_id' => 'custody']);
    $release = ['operation' => 'custody.release', 'transaction_id' => 'custody'];
    /** @var list<array{status: string, item: array{object_id: string, version: string}}> $results */
    $results = Probe::contend($workspace, $release, $release, false);
    foreach ($results as $result) {
        expect($result['status'])->toBe('released');
        expect($result['item'])->toBe($receipt['item']);
    }
    $before = Probe::snapshot($workspace . '/vault');
    expect(Probe::run($workspace, $release)['status'])->toBe('released');
    expect(Probe::snapshot($workspace . '/vault'))->toBe($before);
});

test('changed redundant custody or a damaged release authorization is retained for diagnosis', function (string $damage): void {
    ['workspace' => $workspace, 'directory' => $directory] = custody_workspace($this->archive);
    Probe::run($workspace, ['operation' => 'commit', 'transaction_id' => 'custody']);
    Probe::finish(Probe::start($workspace, ['operation' => 'custody.release', 'transaction_id' => 'custody'], 'custody-release-authorized'));
    file_put_contents($directory . '/' . ($damage === 'authorization' ? 'custody-release.json' : 'custody-1'), 'damaged redundant evidence');
    $before = Probe::snapshot($workspace . '/vault');
    expect(Probe::run($workspace, ['operation' => 'custody.release', 'transaction_id' => 'custody'])['status'])->toBe('quarantined');
    expect(file_exists($directory . '/custody-0'))->toBeTrue();
    expect(Probe::snapshot($workspace . '/vault'))->toBe($before);
    // Cleanup evidence never becomes an independent authority for archival completion.
    expect(Probe::run($workspace, ['operation' => 'recover', 'transaction_id' => 'custody'])['status'])->toBe('complete');
    expect(Probe::snapshot($workspace . '/vault'))->toBe($before);
})->with(['authorization', 'custody']);
