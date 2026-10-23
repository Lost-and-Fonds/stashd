<?php

use Tests\Support\RecordsAndArchives\ArchiveFixture;
use Tests\Support\RecordsAndArchives\OcflInspector;
use Tests\Support\RecordsAndArchives\PreparationProbe;
use Tests\Support\RecordsAndArchives\TransactionProbe as Probe;

/**
 * Build a real preparation request with a source outside the disposable Vault.
 *
 * @return array<string, mixed>
 */
function preparation_request(ArchiveFixture $archive, string $id, string $bytes): array
{
    return [
        'operation' => 'prepare',
        'transaction_id' => $id,
        'uri' => 'urn:example:transaction:item',
        'changes' => ['master' => $archive->input($id . '-' . hash('sha256', $bytes), $bytes)],
        'metadata' => ['event' => $id],
        'preconditions' => [],
    ];
}

test('an unrelated Item commits before a paused custody copy resumes', function (): void {
    /** @var ArchiveFixture $archive */
    $archive = $this->archive;
    $archive->run('vault.initialize');
    $workspace = dirname($archive->path('vault'));
    $bytes = str_repeat('A', 262_144);
    $request = preparation_request($this->archive, 'slow', $bytes);
    $a = Probe::start($workspace, $request, pause: 'custody-copying:master');
    try {
        PreparationProbe::paused($a, 'custody-copying:master');
        $copies = glob($workspace . '/vault/extensions/stashd-transactions/*.preparing-*/custody-0');
        if ($copies === false || count($copies) !== 1) {
            throw new RuntimeException('Expected exactly one private copy in progress.');
        }

        expect($copies)->toHaveCount(1);
        expect(filesize($copies[0]))->toBe(65_536);
        expect(file_get_contents($copies[0]))->toBe(substr($bytes, 0, 65_536));
        expect(file_exists($a['prefix'] . '.resume'))->toBeFalse();
        $other = preparation_request($this->archive, 'independent', 'B bytes');
        $other['uri'] = 'urn:example:independent-item';
        /** @var array{status: string, item: array{object_id: string, version: string}} $b */
        $b = Probe::run($workspace, [...$other, 'operation' => 'execute']);
        expect($b['status'])->toBe('complete');
        expect(proc_get_status($a['process'])['running'])->toBeTrue();
        expect(file_exists($a['prefix'] . '.resume'))->toBeFalse();
        expect(file_get_contents($a['prefix'] . '.out'))->toBe('');
        OcflInspector::assertAcknowledgedHistory($workspace . '/vault', $b['item']['object_id'], 'v1');
        $item = Probe::json(OcflInspector::payloadPath($workspace . '/vault', $b['item']['object_id'], 'v1', 'item.json'));
        expect($item['uri'])->toBe('urn:example:independent-item');
        /** @var list<array{object_id: string, version: string}> $pins */
        $pins = $item['pins'];
        expect($pins)->toHaveCount(1);
        OcflInspector::assertAcknowledgedHistory($workspace . '/vault', $pins[0]['object_id'], 'v1');
        expect(file_get_contents(OcflInspector::payloadPath($workspace . '/vault', $pins[0]['object_id'], 'v1', 'data.bin')))->toBe('B bytes');
        file_put_contents($a['prefix'] . '.resume', 'resume');
        $finished = Probe::finish($a);
        expect($finished['exit'])->toBe(0);
        expect(Probe::json($a['prefix'] . '.out')['status'])->toBe('sealed');
        /** @var array{status: string, transaction_id: string, item: array{object_id: string, version: string}} $receipt */
        $receipt = Probe::run($workspace, ['operation' => 'commit', 'transaction_id' => 'slow']);
        Probe::committed($workspace . '/vault', $receipt, 'slow', ['master' => $bytes]);
    } finally {
        PreparationProbe::cancel([$a]);
    }
});

test('concurrent same ID preparations seal one candidate without clobbering accepted bytes', function (bool $different, bool $killOwner): void {
    /** @var ArchiveFixture $archive */
    $archive = $this->archive;
    $archive->run('vault.initialize');
    $workspace = dirname($archive->path('vault'));
    $owner = preparation_request($this->archive, 'collision', 'original bytes');
    $otherBytes = $different ? 'different bytes' : 'original bytes';
    $contender = preparation_request($this->archive, 'collision', $otherBytes);
    [$a, $b] = PreparationProbe::contend($workspace, $owner, $contender, $killOwner);
    expect($a['status'])->toBe($killOwner ? 'owner-killed' : 'sealed');
    expect($b['status'])->toBe($different && ! $killOwner ? 'conflict' : 'sealed');
    if (! $different && ! $killOwner) {
        expect($b)->toBe($a);
    }

    $candidates = glob($workspace . '/vault/extensions/stashd-transactions/*/candidate.json');
    if ($candidates === false) {
        throw new RuntimeException('Could not inspect candidate directories.');
    }

    $sealed = array_values(array_filter($candidates, static fn (string $path): bool => ! str_contains($path, '.preparing-')));
    expect($sealed)->toHaveCount(1);
    $directory = dirname($sealed[0]);
    expect(file_get_contents($directory . '/custody-0'))->toBe($killOwner ? $otherBytes : 'original bytes');
    $before = Probe::snapshot($workspace . '/vault');
    expect(Probe::run($workspace, ['operation' => 'discover'])['ids'])->toBe(['collision']);
    expect(Probe::snapshot($workspace . '/vault'))->toBe($before);
    /** @var array{status: string, transaction_id: string, item: array{object_id: string, version: string}} $receipt */
    $receipt = Probe::run($workspace, ['operation' => 'recover', 'transaction_id' => 'collision']);
    Probe::committed($workspace . '/vault', $receipt, 'collision', ['master' => $killOwner ? $otherBytes : 'original bytes']);
})->with([false, true])->with([false, true]);

test('a changed Item between preparation verification and sealing explicitly conflicts', function (): void {
    /** @var ArchiveFixture $archive */
    $archive = $this->archive;
    $archive->run('vault.initialize');
    $workspace = dirname($archive->path('vault'));
    $a = Probe::start($workspace, preparation_request($this->archive, 'stale-seal', 'old bytes'), pause: 'candidate-ready');
    try {
        PreparationProbe::paused($a, 'candidate-ready');
        /** @var array{status: string, transaction_id: string, item: array{object_id: string, version: string}} $new */
        $new = Probe::run($workspace, [...preparation_request($this->archive, 'new', 'new bytes'), 'operation' => 'execute']);
        Probe::committed($workspace . '/vault', $new, 'new', ['master' => 'new bytes']);
        file_put_contents($a['prefix'] . '.resume', 'resume');
        expect(Probe::finish($a)['exit'])->toBe(0);
        expect(Probe::json($a['prefix'] . '.out')['status'])->toBe('conflict');
        $before = Probe::snapshot($workspace . '/vault');
        expect(Probe::run($workspace, ['operation' => 'recover', 'transaction_id' => 'stale-seal'])['status'])->toBe('absent');
        expect(Probe::snapshot($workspace . '/vault'))->toBe($before);
        Probe::committed($workspace . '/vault', $new, 'new', ['master' => 'new bytes']);
    } finally {
        PreparationProbe::cancel([$a]);
    }
});
