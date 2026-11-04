<?php

declare(strict_types=1);

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Tests\Support\Integration\Pr1RoundTrip;
use Tests\Support\Integration\Pr2Directory;
use Tests\Support\Integration\Pr4Recovery;
use Tests\Support\RecordsAndArchives\ArchiveFixture;
use Tests\Support\RecordsAndArchives\OcflInspector;
use Tests\Support\RecordsAndArchives\TransactionProbe;

require_once dirname(__DIR__, 2) . '/Support/Integration/configuration-cli.php';

/**
 * This is the Operations route, not the older synchronous broadcast:publish
 * diagnostic. Stop after a real destination rename but before the first report
 * commits; a fresh worker must finish the SAME frozen run from the Vault.
 */
test('SIGKILL after a Broadcast rename replays without false completion, duplicate output or orphaned staging', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $corpus = Pr2Directory::corpus($archive, 3);
        $scope = Pr2Directory::discover($archive, $descriptor, $corpus['source'], 3);
        Pr2Directory::acquire($archive, $descriptor, $scope['run'], 3);
        $original = Pr2Directory::assertPreserved(
            $archive,
            Pr2Directory::members($archive, $descriptor, $scope['stash']),
            $corpus['entries'],
        );

        $destination = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:create', 'filesystem', $archive->path('output')]);
        $frozen = Pr1RoundTrip::command($archive, $descriptor, ['stash:broadcast:select', $scope['stash'], $destination['id']]);
        $job = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:build', $frozen['id']]);
        $assignment = $job['assignment_id'];
        expect($assignment)->not->toBeNull();

        $archive->destroy('inputs');
        $database->exec(<<<'SQL'
        CREATE FUNCTION configuration_proof.pr4_hold_first_report() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            PERFORM pg_advisory_xact_lock(7194, 1);
            RETURN NEW;
        END
        $$;
        CREATE TRIGGER pr4_hold_first_report BEFORE INSERT ON configuration_proof.broadcast_reports
        FOR EACH ROW EXECUTE FUNCTION configuration_proof.pr4_hold_first_report();
        SQL);
        $database->query('SELECT pg_advisory_lock(7194, 1)');
        $child = start_configuration_cli($archive, $descriptor, ['operations:work', '--once']);
        $firstFile = null;
        $firstInode = null;

        try {
            Pr4Recovery::awaitLock($database, $child, 7194, 1);
            $present = [];
            foreach ($corpus['entries'] as $relative => $entry) {
                $path = $archive->path('output/' . $relative);
                clearstatcache(true, $path);
                if (is_file($path)) {
                    expect(hash_file('sha256', $path))->toBe($entry['sha256']);
                    $present[] = $relative;
                }
            }

            expect($present)->toHaveCount(1, 'The real publisher must rename the first file before attempting its report.');
            $firstFile = $present[0];
            $firstInode = fileinode($archive->path('output/' . $firstFile));
            expect($firstInode)->toBeInt();
            $inspection = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:publication', $frozen['id']]);
            expect($inspection['run']['status'])->toBe('running');
            expect($inspection['results'])->toBe([]);
            expect(Pr1RoundTrip::verify($corpus['manifest'], $archive->path('output')))->not->toBe(0);
            expect(Pr4Recovery::results($database, $assignment))->toBe([], 'A renamed file cannot imply an accepted Operations Result.');
        } finally {
            Pr4Recovery::kill($child);
            $database->query('SELECT pg_advisory_unlock(7194, 1)');
            $database->exec('DROP TRIGGER IF EXISTS pr4_hold_first_report ON configuration_proof.broadcast_reports');
            $database->exec('DROP FUNCTION IF EXISTS configuration_proof.pr4_hold_first_report()');
        }

        expect($firstFile)->not->toBeNull();
        Pr4Recovery::expireFixtureDelivery($database);
        Pr2Directory::settle($archive, $descriptor, $assignment, 180);

        clearstatcache(true, $archive->path('output/' . $firstFile));
        expect(fileinode($archive->path('output/' . $firstFile)))->toBe($firstInode);

        $published = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:publication', $frozen['id']]);
        expect($published['run']['status'])->toBe('complete');
        expect($published['results'])->toHaveCount(3);
        expect(array_column($published['results'], 'kind'))->toBe(['file', 'file', 'file']);
        expect(Pr4Recovery::results($database, $assignment))->toHaveCount(1, 'One accepted Result covers the recovered frozen selection.');
        expect(Pr2Directory::assertPreserved(
            $archive,
            Pr2Directory::members($archive, $descriptor, $scope['stash']),
            $corpus['entries'],
        ))
            ->toBe($original, 'Broadcast replay must not mutate canonical archival revisions.');
        foreach ($corpus['entries'] as $relative => $entry) {
            expect(filesize($archive->path('output/' . $relative)))->toBe($entry['bytes']);
            expect(hash_file('sha256', $archive->path('output/' . $relative)))->toBe($entry['sha256']);
        }

        expect(Pr1RoundTrip::verify($corpus['manifest'], $archive->path('output')))->toBe(0, 'Completed recovery must satisfy the independent no-extras oracle.');
        expect(glob($archive->path('output/.stashd-publication-*')))->toBe([]);
    });
});

/**
 * A successful physical publication and even a complete Broadcast run are not
 * sufficient for a stale worker to fabricate an accepted Operations Result.
 * Revoke the actual PostgreSQL write session at the real Result insertion path.
 */
test('revoked Broadcast worker cannot accept a stale Result; a successor recovers the frozen selection', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $corpus = Pr1RoundTrip::microtext($archive);
        $scope = Pr1RoundTrip::discover($archive, $descriptor, $corpus['source']);
        $acquisition = Pr1RoundTrip::acquire($archive, $descriptor, $scope['run']);
        Pr1RoundTrip::settle($archive, $descriptor, $acquisition);
        $preserved = Pr1RoundTrip::preserved($archive, $scope['item']['object_id'], $corpus['bytes']);

        $destination = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:create', 'filesystem', $archive->path('output')]);
        $frozen = Pr1RoundTrip::command($archive, $descriptor, ['stash:broadcast:select', $scope['stash'], $destination['id']]);
        $job = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:build', $frozen['id']]);
        $assignment = $job['assignment_id'];
        expect($assignment)->not->toBeNull();
        $archive->destroy('inputs');

        $database->exec(<<<'SQL'
        CREATE FUNCTION configuration_proof.pr4_hold_result() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            PERFORM pg_advisory_xact_lock(7194, 2);
            RETURN NEW;
        END
        $$;
        CREATE TRIGGER pr4_hold_result BEFORE INSERT ON configuration_proof.operation_results
        FOR EACH ROW EXECUTE FUNCTION configuration_proof.pr4_hold_result();
        SQL);
        $database->query('SELECT pg_advisory_lock(7194, 2)');
        $child = start_configuration_cli($archive, $descriptor, ['operations:work', '--once']);
        $publishedInode = null;

        try {
            $backend = Pr4Recovery::awaitLock($database, $child, 7194, 2);
            $published = $archive->path('output/' . $corpus['relative']);
            expect(is_file($published))->toBeTrue();
            expect(Pr1RoundTrip::verify($corpus['manifest'], $archive->path('output')))->toBe(0);
            clearstatcache(true, $published);
            $publishedInode = fileinode($published);
            expect($publishedInode)->toBeInt();
            $inspection = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:publication', $frozen['id']]);
            expect($inspection['run']['status'])->toBe('complete');
            expect($inspection['results'])->toHaveCount(1);

            expect(Pr4Recovery::results($database, $assignment))->toBe([]);
            $revoke = $database->prepare('SELECT pg_terminate_backend(?)');
            $revoke->execute([$backend]);
            expect(in_array($revoke->fetchColumn(), [true, 't', '1', 1], true))
                ->toBeTrue(
                    'The actual result-writer session must be revoked, not a fabricated Job.',
                );
            Pr4Recovery::awaitExit($child);
        } finally {
            Pr4Recovery::kill($child);
            $database->query('SELECT pg_advisory_unlock(7194, 2)');
            $database->exec('DROP TRIGGER IF EXISTS pr4_hold_result ON configuration_proof.operation_results');
            $database->exec('DROP FUNCTION IF EXISTS configuration_proof.pr4_hold_result()');
        }

        expect(Pr4Recovery::results($database, $assignment))->toBe([], 'The revoked execution must not leave a committed Result.');

        Pr4Recovery::expireFixtureDelivery($database);
        Pr2Directory::settle($archive, $descriptor, $assignment, 180);
        expect(Pr1RoundTrip::verify($corpus['manifest'], $archive->path('output')))->toBe(0);
        clearstatcache(true, $archive->path('output/' . $corpus['relative']));
        expect(fileinode($archive->path('output/' . $corpus['relative'])))->toBe($publishedInode);
        expect(Pr4Recovery::results($database, $assignment))->toHaveCount(1, 'Exactly one successor Result may be accepted.');
        expect(Pr1RoundTrip::preserved($archive, $scope['item']['object_id'], $corpus['bytes'])['pin'])
            ->toBe($preserved['pin'], 'Recovery must preserve the exact original Asset pin.');
    });
});

/**
 * Hold the existing Object lock, not a fake handler, across a real acquisition.
 * Sealed custody and the publication plan must already exist before SIGKILL.
 */
test('source-independent recovery retains the sealed transaction at an interrupted OCFL boundary', function (bool $afterAsset, bool $damage): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database) use ($afterAsset, $damage): void {
        $corpus = Pr1RoundTrip::microtext($archive);
        $scope = Pr1RoundTrip::discover($archive, $descriptor, $corpus['source']);
        $assignment = Pr1RoundTrip::acquire($archive, $descriptor, $scope['run']);
        $attempts = Pr1RoundTrip::command($archive, $descriptor, ['input:acquisitions', $scope['run']])['items'];
        expect($attempts)->toHaveCount(1);
        $transaction = $attempts[0]['id'];
        $directory = $archive->path('vault/extensions/stashd-transactions/' . hash('sha256', $transaction));
        $asset = Pr4Recovery::assetId($archive, $scope['item']['uri']);
        $object = $afterAsset ? $scope['item']['object_id'] : $asset;
        $workspace = dirname($archive->path('vault'));
        $lock = new LockFactory(new FlockStore($workspace))->createLock('stashd:object-write:' . $object, ttl: null);
        expect($lock->acquire())->toBeTrue();
        $child = start_configuration_cli($archive, $descriptor, ['operations:work', '--once'], ['TMPDIR' => $workspace]);
        $assetInventory = null;
        $assetInode = null;
        try {
            Pr4Recovery::awaitFile($directory . '/plan.json', $child);
            if ($afterAsset) {
                $digest = hash('sha256', $asset);
                Pr4Recovery::awaitFile($archive->path('vault/' . substr($digest, 0, 2) . '/' . substr($digest, 2, 2) . '/' . $digest . '/v1/inventory.json.sha512'), $child);
            }

            Pr4Recovery::awaitObjectLock($child);
            // Freeze the observed physical boundary before inspecting all its evidence.
            expect(proc_terminate($child['process'], 19))->toBeTrue();
            $candidate = TransactionProbe::json($directory . '/candidate.json');
            expect(file_get_contents($directory . '/candidate.sha512'))->toBe(hash_file('sha512', $directory . '/candidate.json'));
            expect($candidate['delta']['id'])->toBe($transaction);
            expect($candidate['delta']['uri'])->toBe($scope['item']['uri']);
            expect(str_contains($directory, '.preparing-'))->toBeFalse();
            $change = $candidate['delta']['changes']['original'];
            expect(filesize($directory . '/' . $change['file']))->toBe($change['size']);
            expect(hash_file('sha512', $directory . '/' . $change['file']))->toBe($change['digest']);
            expect(hash_file('sha256', $directory . '/' . $change['file']))->toBe($corpus['digest']);
            $plan = Pr4Recovery::evidence($directory . '/plan.json');
            expect($plan['candidate'])->toBe(hash_file('sha512', $directory . '/candidate.json'));
            expect($plan['assets']['original']['object_id'])->toBe($asset);
            expect($plan['assets']['original']['version'])->toBe('v1');
            expect($plan['item']['object_id'])->toBe($scope['item']['object_id']);
            expect($plan['pins'])->toBe([['object_id' => $asset, 'version' => 'v1']]);
            expect(Pr4Recovery::results($database, $assignment))->toBe([]);
            expect(is_file($directory . '/complete.json'))->toBeFalse();
            $objects = $archive->run('vault.list_objects')['object_ids'];
            expect($objects)->toBe($afterAsset ? [$asset] : []);
            if ($afterAsset) {
                OcflInspector::assertAcknowledgedHistory($archive->path('vault'), $asset, 'v1');
                $assetInventory = OcflInspector::inventoryFor($archive->path('vault'), $asset)['inventory'];
                $payload = OcflInspector::payloadPath($archive->path('vault'), $asset, 'v1', 'data.bin');
                expect(hash_file('sha256', $payload))->toBe($corpus['digest']);
                $assetInode = fileinode($payload);
            }
        } finally {
            Pr4Recovery::kill($child);
            $lock->release();
        }

        $archive->destroy('inputs');
        expect(is_dir($corpus['source']))->toBeFalse();
        $seal = hash_file('sha512', $directory . '/candidate.json');
        $intent = hash_file('sha512', $directory . '/plan.json');
        if ($damage) {
            file_put_contents($payload, 'damaged partial Asset; must not overwrite');
            Pr4Recovery::expireFixtureDelivery($database);
            $worker = configuration_cli($archive, $descriptor, ['operations:work', '--once']);
            expect($worker['code'])->toBe(0, $worker['output'] . $worker['errors']);
            expect(Pr4Recovery::results($database, $assignment))->toBe([]);
            expect(count(Pr4Recovery::attempts($database, $assignment)))->toBeGreaterThan(1);
            $attempt = Pr1RoundTrip::command($archive, $descriptor, ['input:acquisitions', $scope['run']])['items'][0];
            expect($attempt['id'])->toBe($transaction);
            expect($attempt['status'])->toBe('quarantined');
            expect(file_get_contents($payload))->toBe('damaged partial Asset; must not overwrite');
            expect(OcflInspector::inventoryFor($archive->path('vault'), $asset)['inventory'])->toBe($assetInventory);
            expect($archive->run('vault.list_objects')['object_ids'])->toBe([$asset]);
            expect(hash_file('sha512', $directory . '/candidate.json'))->toBe($seal);
            expect(hash_file('sha512', $directory . '/plan.json'))->toBe($intent);
            expect(hash_file('sha512', $directory . '/' . $change['file']))->toBe($change['digest']);
            expect(is_file($directory . '/complete.json'))->toBeFalse();

            return;
        }

        Pr4Recovery::expireFixtureDelivery($database);
        Pr2Directory::settle($archive, $descriptor, $assignment, 120);
        $preserved = Pr1RoundTrip::preserved($archive, $scope['item']['object_id'], $corpus['bytes']);
        expect($preserved['pin'])->toBe($plan['pins'][0]);
        expect($preserved['item']['transaction']['id'])->toBe($transaction);
        expect($preserved['item']['transaction']['candidate'])->toBe($seal);
        $assetRecord = TransactionProbe::json(OcflInspector::payloadPath($archive->path('vault'), $asset, 'v1', '.stashd/asset.json'));
        expect($assetRecord['transaction'])->toBe($preserved['item']['transaction']);
        expect(hash_file('sha512', $directory . '/candidate.json'))->toBe($seal);
        expect(hash_file('sha512', $directory . '/plan.json'))->toBe($intent);
        expect(Pr4Recovery::results($database, $assignment))->toHaveCount(1);
        expect(Pr1RoundTrip::command($archive, $descriptor, ['input:acquisitions', $scope['run']])['items'][0]['id'])->toBe($transaction);
        if ($afterAsset) {
            expect(OcflInspector::inventoryFor($archive->path('vault'), $asset)['inventory'])->toBe($assetInventory);
            expect(fileinode($preserved['payload']))->toBe($assetInode);
        }

        Pr2Directory::broadcast($archive, $descriptor, $scope['stash'], 1);
        expect(Pr1RoundTrip::verify($corpus['manifest'], $archive->path('output')))->toBe(0);
    });
})->with([
    'sealed custody before any Object' => [false, false],
    'verified Asset before its Item pin' => [true, false],
    'damaged partial Asset is quarantined' => [true, true],
]);

/**
 * Observe a real Operations publisher copying a large verified Vault stream.
 * A stopped process is killed only after some, but not all, staged bytes exist.
 */
test('SIGKILL during Operations Broadcast staging exposes no partial final filename', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $source = $archive->input('large.bin', '');
        $writer = fopen($source, 'wb');
        expect($writer)->toBeResource();
        try {
            $chunk = str_repeat('independent staging witness!', 32_768);
            for ($index = 0; $index < 128; $index++) {
                expect(fwrite($writer, $chunk))->toBe(strlen($chunk));
            }
        } finally {
            fclose($writer);
        }

        $size = filesize($source);
        $digest = hash_file('sha256', $source);
        $scope = Pr1RoundTrip::discover($archive, $descriptor, dirname($source));
        $acquisition = Pr1RoundTrip::acquire($archive, $descriptor, $scope['run']);
        Pr2Directory::settle($archive, $descriptor, $acquisition, 120);
        $itemInventory = OcflInspector::inventoryFor($archive->path('vault'), $scope['item']['object_id'])['inventory'];
        $item = TransactionProbe::json(OcflInspector::payloadPath($archive->path('vault'), $scope['item']['object_id'], 'v1', 'item.json'));
        $pin = $item['pins'][0];
        $assetInventory = OcflInspector::inventoryFor($archive->path('vault'), $pin['object_id'])['inventory'];
        OcflInspector::assertAcknowledgedHistory($archive->path('vault'), $pin['object_id'], 'v1');
        expect(hash_file('sha256', OcflInspector::payloadPath($archive->path('vault'), $pin['object_id'], 'v1', 'data.bin')))->toBe($digest);
        $destination = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:create', 'filesystem', $archive->path('output')]);
        $frozen = Pr1RoundTrip::command($archive, $descriptor, ['stash:broadcast:select', $scope['stash'], $destination['id']]);
        $assignment = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:build', $frozen['id']])['assignment_id'];
        $archive->destroy('inputs');
        $child = start_configuration_cli($archive, $descriptor, ['operations:work', '--once']);
        $temporary = null;
        try {
            $deadline = microtime(true) + 30;
            do {
                foreach (glob($archive->path('output/.stashd-publication-*/bytes')) as $path) {
                    clearstatcache(true, $path);
                    $length = filesize($path);
                    if ($length > 0 && $length < $size) {
                        expect(proc_terminate($child['process'], 19))->toBeTrue();
                        $temporary = $path;
                        break 2;
                    }
                }

                if (! proc_get_status($child['process'])['running']) {
                    break;
                }

                usleep(1_000);
            } while (microtime(true) < $deadline);

            expect($temporary)->toBeString('The live worker must actually be interrupted mid-copy.');
            clearstatcache(true, $temporary);
            expect(filesize($temporary))->toBeGreaterThan(0)->toBeLessThan($size);
            expect(file_exists($archive->path('output/large.bin')))->toBeFalse();
            expect(Pr4Recovery::results($database, $assignment))->toBe([]);
        } finally {
            Pr4Recovery::kill($child);
        }

        expect(file_exists($archive->path('output/large.bin')))->toBeFalse();
        expect(Pr1RoundTrip::command($archive, $descriptor, ['broadcast:publication', $frozen['id']])['results'])->toBe([]);
        Pr4Recovery::expireFixtureDelivery($database);
        Pr2Directory::settle($archive, $descriptor, $assignment, 120);
        expect(filesize($archive->path('output/large.bin')))->toBe($size);
        expect(hash_file('sha256', $archive->path('output/large.bin')))->toBe($digest);
        expect(Pr4Recovery::results($database, $assignment))->toHaveCount(1);
        expect(OcflInspector::inventoryFor($archive->path('vault'), $scope['item']['object_id'])['inventory'])->toBe($itemInventory);
        expect(OcflInspector::inventoryFor($archive->path('vault'), $pin['object_id'])['inventory'])->toBe($assetInventory);
        expect(glob($archive->path('output/.stashd-publication-*')))->toBe([], 'Recovery must not strand the dead publisher staging bytes.');
    });
});

/**
 * Revoke only the claim session while the actual Broadcast handler remains alive.
 * Its successor must replace the Attempt before the old handler is released.
 */
test('a concurrent Broadcast successor replaces an active Attempt and fences the living stale Result writer', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $corpus = Pr1RoundTrip::microtext($archive);
        $scope = Pr1RoundTrip::discover($archive, $descriptor, $corpus['source']);
        Pr1RoundTrip::settle($archive, $descriptor, Pr1RoundTrip::acquire($archive, $descriptor, $scope['run']));
        $preserved = Pr1RoundTrip::preserved($archive, $scope['item']['object_id'], $corpus['bytes']);
        $destination = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:create', 'filesystem', $archive->path('output')]);
        $frozen = Pr1RoundTrip::command($archive, $descriptor, ['stash:broadcast:select', $scope['stash'], $destination['id']]);
        $assignment = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:build', $frozen['id']])['assignment_id'];
        $archive->destroy('inputs');
        $database->exec(<<<'SQL'
        CREATE FUNCTION configuration_proof.pr4_hold_takeover() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            PERFORM pg_advisory_xact_lock(7194, 4);
            RETURN NEW;
        END $$;
        CREATE TRIGGER pr4_hold_takeover BEFORE INSERT ON configuration_proof.broadcast_reports
        FOR EACH ROW EXECUTE FUNCTION configuration_proof.pr4_hold_takeover();
        SQL);
        $database->query('SELECT pg_advisory_lock(7194, 4)');
        $old = start_configuration_cli($archive, $descriptor, ['operations:work', '--once']);
        $next = null;
        try {
            $writer = Pr4Recovery::awaitLock($database, $old, 7194, 4);
            $attempts = Pr4Recovery::attempts($database, $assignment);
            expect($attempts)->toHaveCount(1);
            expect($attempts[0]['outcome'])->toBe('running');
            expect((int) $attempts[0]['backend_pid'])->not->toBe($writer, 'The publication and claim sessions must be distinct.');
            $inode = fileinode($archive->path('output/' . $corpus['relative']));
            expect(hash_file('sha256', $archive->path('output/' . $corpus['relative'])))->toBe($corpus['digest']);
            $revoke = $database->prepare('SELECT pg_terminate_backend(?)');
            $revoke->execute([$attempts[0]['backend_pid']]);
            expect($revoke->fetchColumn())->toBeTrue();
            Pr4Recovery::expireFixtureDelivery($database);
            $next = start_configuration_cli($archive, $descriptor, ['operations:work', '--once']);
            $successor = Pr4Recovery::awaitSuccessor($database, $next, $assignment, $attempts[0]['uuid']);
            expect(proc_get_status($old['process'])['running'])->toBeTrue('Takeover must happen while the stale handler is alive.');
            expect($successor['outcome'])->toBe('running');
            expect(Pr4Recovery::results($database, $assignment))->toBe([]);
            $database->query('SELECT pg_advisory_unlock(7194, 4)');
            Pr4Recovery::awaitExit($old, 20);
            Pr4Recovery::awaitExit($next, 20);
        } finally {
            Pr4Recovery::kill($old);
            if ($next !== null) {
                Pr4Recovery::kill($next);
            }

            $database->query('SELECT pg_advisory_unlock(7194, 4)');
            $database->exec('DROP TRIGGER pr4_hold_takeover ON configuration_proof.broadcast_reports');
            $database->exec('DROP FUNCTION configuration_proof.pr4_hold_takeover()');
        }

        Pr2Directory::settle($archive, $descriptor, $assignment, 120);
        $results = Pr4Recovery::results($database, $assignment);
        expect($results)->toHaveCount(1);
        expect($results[0]['attempt_id'])->toBe($successor['uuid'])->not->toBe($attempts[0]['uuid']);
        expect(array_column(Pr4Recovery::attempts($database, $assignment), 'outcome'))->toBe(['interrupted', 'completed']);
        expect(fileinode($archive->path('output/' . $corpus['relative'])))->toBe($inode);
        expect(Pr1RoundTrip::verify($corpus['manifest'], $archive->path('output')))->toBe(0);
        expect(Pr1RoundTrip::preserved($archive, $scope['item']['object_id'], $corpus['bytes'])['pin'])->toBe($preserved['pin']);
    });
});

/**
 * Change only the disposable destination after a real interrupted rename.
 * Recovery must reject conflicting paths and leave unrelated operator files alone.
 */
test('interrupted Broadcast recovery confines destination writes and cleanup', function (string $fault): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database) use ($fault): void {
        $corpus = Pr1RoundTrip::microtext($archive);
        $scope = Pr1RoundTrip::discover($archive, $descriptor, $corpus['source']);
        Pr1RoundTrip::settle($archive, $descriptor, Pr1RoundTrip::acquire($archive, $descriptor, $scope['run']));
        $preserved = Pr1RoundTrip::preserved($archive, $scope['item']['object_id'], $corpus['bytes']);
        $destination = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:create', 'filesystem', $archive->path('output')]);
        $frozen = Pr1RoundTrip::command($archive, $descriptor, ['stash:broadcast:select', $scope['stash'], $destination['id']]);
        $assignment = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:build', $frozen['id']])['assignment_id'];
        $archive->destroy('inputs');
        $database->exec(<<<'SQL'
        CREATE FUNCTION configuration_proof.pr4_hold_confinement() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            PERFORM pg_advisory_xact_lock(7194, 5);
            RETURN NEW;
        END $$;
        CREATE TRIGGER pr4_hold_confinement BEFORE INSERT ON configuration_proof.broadcast_reports
        FOR EACH ROW EXECUTE FUNCTION configuration_proof.pr4_hold_confinement();
        SQL);
        $database->query('SELECT pg_advisory_lock(7194, 5)');
        $child = start_configuration_cli($archive, $descriptor, ['operations:work', '--once']);
        $final = $archive->path('output/' . $corpus['relative']);
        try {
            Pr4Recovery::awaitLock($database, $child, 7194, 5);
            expect(hash_file('sha256', $final))->toBe($corpus['digest']);
            expect(Pr4Recovery::results($database, $assignment))->toBe([]);
        } finally {
            Pr4Recovery::kill($child);
            $database->query('SELECT pg_advisory_unlock(7194, 5)');
            $database->exec('DROP TRIGGER pr4_hold_confinement ON configuration_proof.broadcast_reports');
            $database->exec('DROP FUNCTION configuration_proof.pr4_hold_confinement()');
        }

        $unrelated = $archive->path('output/unrelated');
        file_put_contents($unrelated, 'operator-owned; never prune');
        $unrelatedInode = fileinode($unrelated);
        $lookalike = $archive->path('output/.stashd-publication-' . str_repeat('a', 32));
        mkdir($lookalike, 0o700);
        file_put_contents($lookalike . '/bytes', 'not application-owned evidence');
        $lookalikeInode = fileinode($lookalike . '/bytes');
        $outside = $archive->path('outside');
        mkdir($outside, 0o700);
        $sentinel = $outside . '/' . basename($final);
        file_put_contents($sentinel, 'outside destination; retain');
        $outsideInode = fileinode($sentinel);
        unlink($final);
        if ($fault === 'conflict') {
            file_put_contents($final, 'consumer changed output');
        } elseif ($fault === 'file symlink') {
            symlink($sentinel, $final);
        } else {
            rmdir(dirname($final));
            symlink($outside, dirname($final));
        }

        $obstruction = $fault === 'parent symlink' ? dirname($final) : $final;
        clearstatcache();
        $obstructionStat = lstat($obstruction);
        Pr4Recovery::expireFixtureDelivery($database);
        $worker = configuration_cli($archive, $descriptor, ['operations:work', '--once']);
        expect($worker['code'])->toBe(0, $worker['output'] . $worker['errors']);
        $attempts = Pr4Recovery::attempts($database, $assignment);
        expect(count($attempts))->toBeGreaterThan(1, 'Real successors must try the interrupted Demand.');
        expect($attempts[0]['outcome'])->toBe('interrupted');
        expect(array_values(array_unique(array_column(array_slice($attempts, 1), 'outcome'))))->toBe(['failed']);
        expect(Pr4Recovery::results($database, $assignment))->toBe([]);
        $inspection = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:publication', $frozen['id']]);
        expect($inspection['run']['status'])->toBe('failed');
        expect($inspection['results'])->toBe([]);
        clearstatcache();
        $identityFields = array_flip(['dev', 'ino', 'mode', 'size', 'mtime', 'ctime']);
        // Reading a conflicting file to verify it may update atime, but must not change it.
        expect(array_intersect_key(lstat($obstruction), $identityFields))->toBe(array_intersect_key($obstructionStat, $identityFields));
        if ($fault === 'conflict') {
            expect(file_get_contents($final))->toBe('consumer changed output');
        } else {
            expect(is_link($obstruction))->toBeTrue();
            expect(readlink($obstruction))->toBe($fault === 'file symlink' ? $sentinel : $outside);
        }

        expect(file_get_contents($sentinel))->toBe('outside destination; retain');
        expect(fileinode($sentinel))->toBe($outsideInode);
        expect(scandir($outside))->toBe(['.', '..', basename($sentinel)]);
        expect(file_get_contents($unrelated))->toBe('operator-owned; never prune');
        expect(fileinode($unrelated))->toBe($unrelatedInode);
        expect(file_get_contents($lookalike . '/bytes'))->toBe('not application-owned evidence');
        expect(fileinode($lookalike . '/bytes'))->toBe($lookalikeInode);
        expect(Pr1RoundTrip::preserved($archive, $scope['item']['object_id'], $corpus['bytes'])['pin'])->toBe($preserved['pin']);
    });
})->with(['conflict', 'file symlink', 'parent symlink']);
