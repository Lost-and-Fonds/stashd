<?php

use Tests\Support\RecordsAndArchives\OcflInspector;
use Tests\Support\RecordsAndArchives\ProjectionProbe;
use Tests\Support\RecordsAndArchives\TransactionProbe as Probe;

test('projection rebuild isolates damaged history and replaces stale rows without changing Vault bytes', function (string $damage): void {
    $this->archive->run('vault.initialize');
    $workspace = dirname($this->archive->path('vault'));
    $root = $workspace . '/vault';
    $receipts = [];
    $expectedItems = [];
    $expectedPins = [];
    foreach (['healthy', 'damaged'] as $label) {
        foreach (['v1', 'v2'] as $version) {
            $receipt = Probe::run($workspace, [
                'operation' => 'execute',
                'transaction_id' => $label . $version,
                'uri' => 'urn:example:projection:' . $label,
                'changes' => $version === 'v2' && $label === 'healthy' ? [] : ['master' => $this->archive->input($label . $version, $label . $version)],
                'metadata' => [],
                'preconditions' => [],
            ]);
            expect($receipt['status'])->toBe('complete');
            $receipts[$label] = $receipt;
            if ($label === 'healthy') {
                OcflInspector::assertAcknowledgedHistory($root, $receipt['item']['object_id'], $version);
                $record = Probe::json(OcflInspector::payloadPath($root, $receipt['item']['object_id'], $version, 'item.json'));
                $expectedItems[] = $receipt['item'];
                foreach ($record['pins'] as $pin) {
                    $expectedPins[] = [
                        'item_object_id' => $receipt['item']['object_id'],
                        'item_version' => $version,
                        'asset_object_id' => $pin['object_id'],
                        'asset_version' => $pin['version'],
                    ];
                }
            }
        }
    }
    $name = 'stashd_tx_test_' . bin2hex(random_bytes(8));
    $fixture = ['database' => $name, 'username' => $name, 'schema' => $name];
    $database = null;
    $created = false;
    try {
        $projection = Probe::run($workspace, ['operation' => 'projection.describe', 'fixture' => $fixture]);
        $created = true;
        $database = ProjectionProbe::connect($projection, $name);
        $rebuild = ['operation' => 'projection.rebuild', 'fixture' => $fixture];
        expect(Probe::run($workspace, $rebuild)['items'])->toHaveCount(4);
        $item = $receipts['damaged']['item']['object_id'];
        $record = Probe::json(OcflInspector::payloadPath($root, $item, 'v1', 'item.json'));
        $pin = $record['pins'][0];
        $target = str_starts_with($damage, 'asset') ? $pin['object_id'] : $item;
        $inventory = OcflInspector::inventoryFor($root, $target)['path'];
        $itemPath = dirname(OcflInspector::inventoryFor($root, $item)['path']);
        if ($damage === 'item-payload' || $damage === 'asset-payload') {
            $path = OcflInspector::payloadPath($root, $target, 'v1', $damage === 'item-payload' ? 'item.json' : 'data.bin');
        } elseif ($damage === 'asset-missing') {
            $path = dirname($inventory) . '/0=ocfl_object_1.1';
        } else {
            $path = $inventory;
        }
        $original = file_get_contents($path);
        if ($damage === 'asset-missing') {
            unlink($path);
        } else {
            file_put_contents($path, '{damaged');
        }
        if ($damage === 'unidentified') {
            // Remove every discovery identity hint, retaining a physical damaged root.
            foreach (['v1', 'v2'] as $version) {
                file_put_contents(dirname($inventory) . '/' . $version . '/inventory.json', '{damaged');
            }
        }
        $before = Probe::snapshot($root);
        $previous = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($attempt === 1) {
                expect(Probe::run($workspace, ['operation' => 'projection.destroy', 'fixture' => $fixture])['verified_absent'])->toBeTrue();
            }
            $report = Probe::run($workspace, $rebuild);
            expect($report['items'])->toBe($expectedItems);
            $actualItems = $database->query('SELECT object_id, version FROM "' . $name . '".items ORDER BY version')->fetchAll(PDO::FETCH_ASSOC);
            $actualPins = $database->query('SELECT * FROM "' . $name . '".pins ORDER BY item_version')->fetchAll(PDO::FETCH_ASSOC);
            expect($actualItems)->toBe($expectedItems);
            expect($actualPins)->toBe($expectedPins);
            $incidents = $database->query('SELECT * FROM "' . $name . '".incidents ORDER BY path')->fetchAll(PDO::FETCH_ASSOC);
            expect($incidents)->toBe($report['incidents'])->toHaveCount(1);
            expect($incidents[0]['reason'])->not->toBe('');
            expect($incidents[0]['object_id'])->toBe($damage === 'unidentified' ? null : $item);
            expect($incidents[0]['path'])->toBe(ltrim(substr($itemPath, strlen($root)), '/'));
            if (str_starts_with($damage, 'asset')) {
                expect($incidents[0]['dependency_object_id'])->toBe($pin['object_id']);
                expect($incidents[0]['dependency_version'])->toBe('v1');
            }
            if ($previous !== null) {
                expect($report)->toBe($previous);
            }
            $previous = $report;
            expect(Probe::snapshot($root))->toBe($before);
        }
        // An SQL failure after deletion must roll back rows and incidents together.
        $database->exec('ALTER TABLE "' . $name . '".items ADD CONSTRAINT reject_new CHECK (version != \'v2\') NOT VALID');
        expect(fn () => Probe::run($workspace, $rebuild))->toThrow(\PHPUnit\Framework\AssertionFailedError::class, 'reject_new');
        expect($database->query('SELECT object_id, version FROM "' . $name . '".items ORDER BY version')->fetchAll(PDO::FETCH_ASSOC))->toBe($expectedItems);
        expect($database->query('SELECT * FROM "' . $name . '".incidents ORDER BY path')->fetchAll(PDO::FETCH_ASSOC))->toBe($previous['incidents']);
        expect(Probe::snapshot($root))->toBe($before);
        $database->exec('ALTER TABLE "' . $name . '".items DROP CONSTRAINT reject_new');
        if ($damage !== 'unidentified') {
            file_put_contents($path, $original);
            expect(Probe::run($workspace, $rebuild)['incidents'])->toBe([]);
            expect((int) $database->query('SELECT count(*) FROM "' . $name . '".items')->fetchColumn())->toBe(4);
            expect((int) $database->query('SELECT count(*) FROM "' . $name . '".incidents')->fetchColumn())->toBe(0);
        }
    } finally {
        $database = null;
        if ($created) {
            Probe::run($workspace, ['operation' => 'projection.dispose', 'fixture' => $fixture]);
        }
    }
})->with(['item-payload', 'item-inventory', 'asset-payload', 'asset-missing', 'unidentified']);

test('inventory provider read failure aborts rebuild and preserves existing SQL projection', function (): void {
    $this->archive->run('vault.initialize');
    $workspace = dirname($this->archive->path('vault'));
    $root = $workspace . '/vault';
    $receipts = [];
    foreach (['healthy', 'unavailable'] as $label) {
        $receipts[$label] = Probe::run($workspace, [
            'operation' => 'execute',
            'transaction_id' => $label,
            'uri' => 'urn:example:read-failure:' . $label,
            'changes' => ['master' => $this->archive->input($label, $label)],
            'metadata' => [],
            'preconditions' => [],
        ]);
        expect($receipts[$label]['status'])->toBe('complete');
    }
    $name = 'stashd_tx_test_' . bin2hex(random_bytes(8));
    $fixture = ['database' => $name, 'username' => $name, 'schema' => $name];
    $created = false;
    $database = null;
    try {
        $projection = Probe::run($workspace, ['operation' => 'projection.describe', 'fixture' => $fixture]);
        $created = true;
        $database = ProjectionProbe::connect($projection, $name);
        $rebuild = ['operation' => 'projection.rebuild', 'fixture' => $fixture];
        expect(Probe::run($workspace, $rebuild)['items'])->toHaveCount(2);
        // Preserve all three tables, including an existing diagnostic, across failed I/O.
        $database->exec('INSERT INTO "' . $name . '".incidents (path, reason) VALUES (\'previous-scan\', \'existing diagnostic\')');
        $beforeSql = [];
        foreach (['items', 'pins', 'incidents'] as $table) {
            $beforeSql[$table] = $database->query('SELECT * FROM "' . $name . '".' . $table . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
        }
        $itemId = $receipts['unavailable']['item']['object_id'];
        $inventory = OcflInspector::inventoryFor($root, $itemId)['path'];
        $objectPath = ltrim(substr(dirname($inventory), strlen($root)), '/');
        $beforeVault = Probe::snapshot($root);
        expect(fn () => Probe::run($workspace, [...$rebuild, 'projection_read_fault' => $objectPath]))
            ->toThrow(\PHPUnit\Framework\AssertionFailedError::class, 'Injected inventory storage read failure.');
        foreach ($beforeSql as $table => $rows) {
            expect($database->query('SELECT * FROM "' . $name . '".' . $table . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC))->toBe($rows);
        }
        expect(Probe::snapshot($root))->toBe($beforeVault);
        // Readable malformed bytes still degrade only the affected Item.
        foreach ([$inventory, dirname($inventory) . '/v1/inventory.json'] as $path) {
            file_put_contents($path, '{damaged');
        }
        $damagedVault = Probe::snapshot($root);
        $report = Probe::run($workspace, $rebuild);
        expect($report['items'])->toBe([$receipts['healthy']['item']]);
        expect($report['incidents'])->toHaveCount(1);
        expect($report['incidents'][0]['object_id'])->toBeNull();
        expect($report['incidents'][0]['path'])->toBe($objectPath);
        $record = Probe::json(OcflInspector::payloadPath($root, $receipts['healthy']['item']['object_id'], 'v1', 'item.json'));
        ProjectionProbe::assertRebuilt($database, $projection, $receipts['healthy']['item'], $record['pins']);
        expect($database->query('SELECT * FROM "' . $name . '".incidents')->fetchAll(PDO::FETCH_ASSOC))->toBe($report['incidents']);
        expect(Probe::snapshot($root))->toBe($damagedVault);
    } finally {
        $database = null;
        if ($created) {
            Probe::run($workspace, ['operation' => 'projection.dispose', 'fixture' => $fixture]);
        }
    }
});
