<?php

declare(strict_types=1);

use Tests\Support\Integration\Pr1RoundTrip;
use Tests\Support\Integration\Pr2Directory;
use Tests\Support\Integration\Pr4Recovery;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

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
            $resultCount = $database->prepare('SELECT count(*) FROM configuration_proof.operation_results WHERE assignment_id = ?');
            $resultCount->execute([$assignment]);
            expect((int) $resultCount->fetchColumn())->toBe(0, 'A renamed file cannot imply an accepted Operations Result.');
        } finally {
            Pr4Recovery::kill($child);
            $database->query('SELECT pg_advisory_unlock(7194, 1)');
            $database->exec('DROP TRIGGER IF EXISTS pr4_hold_first_report ON configuration_proof.broadcast_reports');
            $database->exec('DROP FUNCTION IF EXISTS configuration_proof.pr4_hold_first_report()');
        }

        expect($firstFile)->not->toBeNull();
        Pr4Recovery::expireFixtureDelivery($database);
        Pr2Directory::settle($archive, $descriptor, $assignment, 180);

        // A dead publisher may leave a private workspace. Recovery must not
        // leave an extra directory in the user's promised exact output set.
        expect(glob($archive->path('output/.stashd-publication-*')))->toBe([]);
        expect(Pr1RoundTrip::verify($corpus['manifest'], $archive->path('output')))->toBe(0);
        clearstatcache(true, $archive->path('output/' . $firstFile));
        expect(fileinode($archive->path('output/' . $firstFile)))->toBe($firstInode);

        $published = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:publication', $frozen['id']]);
        expect($published['run']['status'])->toBe('complete');
        expect($published['results'])->toHaveCount(3);
        expect(array_column($published['results'], 'kind'))->toBe(['file', 'file', 'file']);
        $results = $database->prepare('SELECT count(*) FROM configuration_proof.operation_results WHERE assignment_id = ?');
        $results->execute([$assignment]);
        expect((int) $results->fetchColumn())->toBe(1, 'One accepted Result covers the recovered frozen selection.');
        expect(Pr2Directory::assertPreserved(
            $archive,
            Pr2Directory::members($archive, $descriptor, $scope['stash']),
            $corpus['entries'],
        ))->toBe($original, 'Broadcast replay must not mutate canonical archival revisions.');
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

            $results = $database->prepare('SELECT count(*) FROM configuration_proof.operation_results WHERE assignment_id = ?');
            $results->execute([$assignment]);
            expect((int) $results->fetchColumn())->toBe(0);
            $revoke = $database->prepare('SELECT pg_terminate_backend(?)');
            $revoke->execute([$backend]);
            expect(in_array($revoke->fetchColumn(), [true, 't', '1', 1], true))->toBeTrue(
                'The actual result-writer session must be revoked, not a fabricated Job.',
            );
            Pr4Recovery::awaitExit($child);
        } finally {
            Pr4Recovery::kill($child);
            $database->query('SELECT pg_advisory_unlock(7194, 2)');
            $database->exec('DROP TRIGGER IF EXISTS pr4_hold_result ON configuration_proof.operation_results');
            $database->exec('DROP FUNCTION IF EXISTS configuration_proof.pr4_hold_result()');
        }

        $results = $database->prepare('SELECT count(*) FROM configuration_proof.operation_results WHERE assignment_id = ?');
        $results->execute([$assignment]);
        expect((int) $results->fetchColumn())->toBe(0, 'The revoked execution must not leave a committed Result.');

        Pr4Recovery::expireFixtureDelivery($database);
        Pr2Directory::settle($archive, $descriptor, $assignment, 180);
        expect(Pr1RoundTrip::verify($corpus['manifest'], $archive->path('output')))->toBe(0);
        clearstatcache(true, $archive->path('output/' . $corpus['relative']));
        expect(fileinode($archive->path('output/' . $corpus['relative'])))->toBe($publishedInode);
        $results->execute([$assignment]);
        expect((int) $results->fetchColumn())->toBe(1, 'Exactly one successor Result may be accepted.');
        expect(Pr1RoundTrip::preserved($archive, $scope['item']['object_id'], $corpus['bytes'])['pin'])
            ->toBe($preserved['pin'], 'Recovery must preserve the exact original Asset pin.');
    });
});
