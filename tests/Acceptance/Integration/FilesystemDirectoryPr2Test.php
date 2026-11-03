<?php

declare(strict_types=1);

use Tests\Support\Integration\Pr1RoundTrip;
use Tests\Support\Integration\Pr2Directory;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

require_once dirname(__DIR__, 2) . '/Support/Integration/configuration-cli.php';

/**
 * Demand a real independently verified directory round trip, then repeat the
 * scan and publication to catch duplicate Items and needless version writes.
 */
test('three separate files survive bounded acquisition, unchanged rescans and repeated Broadcast', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $corpus = Pr2Directory::corpus($archive, 3);
        $scope = Pr2Directory::discover($archive, $descriptor, $corpus['source'], 3);
        Pr2Directory::acquire($archive, $descriptor, $scope['run'], 3);
        Pr2Directory::assertPerFileAssignments($database, $scope['run'], 3);
        $beforeMembers = Pr2Directory::members($archive, $descriptor, $scope['stash']);
        $beforeArchives = Pr2Directory::assertPreserved($archive, $beforeMembers, $corpus['entries']);

        $first = Pr2Directory::broadcast($archive, $descriptor, $scope['stash'], 3);
        expect(Pr1RoundTrip::verify($corpus['manifest'], $archive->path('output')))->toBe(0);
        $beforeInodes = Pr2Directory::inodes($archive->path('output'), $corpus['entries']);

        $rescan = Pr1RoundTrip::command($archive, $descriptor, ['input:discover', $scope['input']]);
        expect($rescan['run_id'])->not->toBe($scope['run']);
        Pr2Directory::settle($archive, $descriptor, $rescan['assignment_id'], 120);
        Pr2Directory::acquire($archive, $descriptor, $rescan['run_id'], 3);
        Pr2Directory::assertPerFileAssignments($database, $rescan['run_id'], 3);

        $afterMembers = Pr2Directory::members($archive, $descriptor, $scope['stash']);
        expect($afterMembers)->toBe($beforeMembers);
        $afterArchives = Pr2Directory::assertPreserved($archive, $afterMembers, $corpus['entries']);
        expect($afterArchives)->toBe($beforeArchives, 'An unchanged rescan must not publish another canonical Item or Asset version.');

        $archive->destroy('inputs');
        Pr2Directory::broadcast($archive, $descriptor, $scope['stash'], 3, $first['broadcast']);
        expect(Pr1RoundTrip::verify($corpus['manifest'], $archive->path('output')))->toBe(0);
        expect(Pr2Directory::inodes($archive->path('output'), $corpus['entries']))->toBe($beforeInodes);
    });
});

/**
 * Deliberately omit the second 100-item page and prove a partially published
 * directory cannot masquerade as a complete 101-file round trip.
 *
 * This negative control copies fixture bytes solely to test the external oracle;
 * it is not the Stashd publication path or evidence that an app Job ran.
 */
test('external verification catches an omitted discovery/admission page', function (): void {
    $archive = new ArchiveFixture();
    try {
        $corpus = Pr2Directory::corpus($archive, 101);
        $output = $archive->path('partial-output');
        expect(mkdir($output, 0o700))->toBeTrue();
        $paths = array_keys($corpus['entries']);
        foreach (array_slice($paths, 0, 100) as $relative) {
            $destination = $output . '/' . $relative;
            if (! is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0o700, true);
            }

            expect(copy($corpus['source'] . '/' . $relative, $destination))->toBeTrue();
        }

        expect(Pr1RoundTrip::verify($corpus['manifest'], $output))->not->toBe(0, 'The verifier must refuse a completed-looking 100/101 output.');
        $last = $paths[100];
        if (! is_dir(dirname($output . '/' . $last))) {
            mkdir(dirname($output . '/' . $last), 0o700, true);
        }

        expect(copy($corpus['source'] . '/' . $last, $output . '/' . $last))->toBeTrue();
        expect(Pr1RoundTrip::verify($corpus['manifest'], $output))->toBe(0);
    } finally {
        $archive->dispose();
    }
});

/**
 * Reject actual per-file Demand admission in PostgreSQL after the first source
 * is accepted; retry the same run after removing the fault.
 *
 * The sequence records entry into the failing trigger even though PostgreSQL
 * rolls the failed admission back. Advancing a cursor past that source must
 * leave a missing file and fail this full output/OCFL proof.
 */
test('failed Worker admission leaves a replayable source instead of skipping it', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $corpus = Pr2Directory::corpus($archive, 3);
        $scope = Pr2Directory::discover($archive, $descriptor, $corpus['source'], 3);
        $database->exec(<<<'SQL'
        CREATE SEQUENCE configuration_proof.pr2_admission_visits;
        CREATE FUNCTION configuration_proof.pr2_reject_admission() RETURNS trigger LANGUAGE plpgsql AS $$
        DECLARE visited bigint;
        BEGIN
            IF NEW.payload::jsonb ->> 'sourceId' IS NOT NULL THEN
                visited := nextval('configuration_proof.pr2_admission_visits');
                IF visited >= 2 THEN
                    RAISE EXCEPTION 'PR2 intentional per-file admission rejection';
                END IF;
            END IF;
            RETURN NEW;
        END
        $$;
        CREATE TRIGGER pr2_reject_admission BEFORE INSERT ON configuration_proof.operation_demands
        FOR EACH ROW EXECUTE FUNCTION configuration_proof.pr2_reject_admission();
        SQL);
        try {
            // The coordinator may admit directly or through a Job. Both use the
            // real production Worker and must encounter this durable DB trigger.
            configuration_cli($archive, $descriptor, ['input:acquire', $scope['run']]);
            configuration_cli($archive, $descriptor, ['operations:work', '--once', '--limit=16']);
            $visits = (int) $database->query('SELECT last_value FROM configuration_proof.pr2_admission_visits')->fetchColumn();
            expect($visits)->toBeGreaterThanOrEqual(2, 'The fault must actually reject a per-file demand.');
            $status = Pr2Directory::admission($archive, $descriptor, $scope['run'], 3);
            expect($status['status'])->not->toBe('complete');
            Pr2Directory::assertCursorCovered($database, $scope['run'], $status['cursor']);
        } finally {
            $database->exec('DROP TRIGGER pr2_reject_admission ON configuration_proof.operation_demands');
            $database->exec('DROP FUNCTION configuration_proof.pr2_reject_admission()');
            $database->exec('DROP SEQUENCE configuration_proof.pr2_admission_visits');
        }

        Pr2Directory::acquire($archive, $descriptor, $scope['run'], 3);
        Pr2Directory::assertPerFileAssignments($database, $scope['run'], 3, allowRetries: true);
        Pr2Directory::assertPreserved($archive, Pr2Directory::members($archive, $descriptor, $scope['stash']), $corpus['entries']);
        $archive->destroy('inputs');
        Pr2Directory::broadcast($archive, $descriptor, $scope['stash'], 3);
        expect(Pr1RoundTrip::verify($corpus['manifest'], $archive->path('output')))->toBe(0);
    });
});

/**
 * Kill the process while committing acquisition admission progress, then require
 * a new process to replay any already admitted Jobs without losing a file.
 *
 * Proposed PR 2 persistence seam: discovery_runs.admission_cursor stores the
 * last successfully admitted source ID. If the implementation chooses another
 * name, review the matching test probe; never weaken the crash/fidelity outcome.
 */
test('worker admission committed before cursor advancement is safe after SIGKILL', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $corpus = Pr2Directory::corpus($archive, 3);
        $scope = Pr2Directory::discover($archive, $descriptor, $corpus['source'], 3);
        $database->exec(<<<'SQL'
        CREATE FUNCTION configuration_proof.pr2_hold_cursor() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            IF NEW.admission_cursor IS DISTINCT FROM OLD.admission_cursor THEN
                PERFORM pg_advisory_xact_lock(7188, 2);
            END IF;
            RETURN NEW;
        END
        $$;
        CREATE TRIGGER pr2_hold_cursor BEFORE UPDATE OF admission_cursor ON configuration_proof.discovery_runs
        FOR EACH ROW EXECUTE FUNCTION configuration_proof.pr2_hold_cursor();
        SQL);
        $database->query('SELECT pg_advisory_lock(7188, 2)');
        $children = [];
        $blocked = false;
        try {
            // A direct admission implementation blocks in the CLI process;
            // a coordinator-Job implementation blocks in the worker process.
            $children[] = start_configuration_cli($archive, $descriptor, ['input:acquire', $scope['run']]);
            $children[] = start_configuration_cli($archive, $descriptor, ['operations:work', '--once']);
            $deadline = microtime(true) + 15;
            do {
                $waiting = (int) $database
                    ->query(
                        "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND classid = 7188 AND objid = 2
                    AND NOT granted AND database = (SELECT oid FROM pg_database WHERE datname = current_database())",
                    )
                    ->fetchColumn();
                if ($waiting > 0) {
                    $blocked = true;
                    break;
                }

                usleep(20_000);
            } while (microtime(true) < $deadline);

            expect($blocked)->toBeTrue('An actual cursor write must reach the post-admission barrier before the crash.');
            expect((int) $database->query("SELECT count(*) FROM configuration_proof.operation_demands WHERE payload::jsonb ->> 'sourceId' IS NOT NULL")->fetchColumn())
                ->toBeGreaterThan(0, 'Per-file admission must already be committed before the cursor barrier.');
        } finally {
            foreach ($children as $child) {
                if (proc_get_status($child['process'])['running']) {
                    proc_terminate($child['process'], 9);
                }

                finish_configuration_cli($child);
            }

            $database->query('SELECT pg_advisory_unlock(7188, 2)');
            $database->exec('DROP TRIGGER pr2_hold_cursor ON configuration_proof.discovery_runs');
            $database->exec('DROP FUNCTION configuration_proof.pr2_hold_cursor()');
        }

        $interrupted = Pr2Directory::admission($archive, $descriptor, $scope['run'], 3);
        Pr2Directory::assertCursorCovered($database, $scope['run'], $interrupted['cursor']);

        // Only test-fixture queue visibility is accelerated; no production TTL
        // policy is changed to make this failure proof finish quickly.
        $database->exec("UPDATE configuration_proof.messenger_messages SET delivered_at = CURRENT_TIMESTAMP - INTERVAL '61 seconds' WHERE delivered_at IS NOT NULL");
        Pr2Directory::acquire($archive, $descriptor, $scope['run'], 3);
        Pr2Directory::assertPerFileAssignments($database, $scope['run'], 3, allowRetries: true);
        Pr2Directory::assertPreserved($archive, Pr2Directory::members($archive, $descriptor, $scope['stash']), $corpus['entries']);
        $archive->destroy('inputs');
        Pr2Directory::broadcast($archive, $descriptor, $scope['stash'], 3);
        expect(Pr1RoundTrip::verify($corpus['manifest'], $archive->path('output')))->toBe(0);
    });
});
