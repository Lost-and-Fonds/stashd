<?php

declare(strict_types=1);

use Tests\Support\Integration\Pr1RoundTrip;
use Tests\Support\Integration\Pr2Directory;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

require_once dirname(__DIR__, 2) . '/Support/Integration/configuration-cli.php';

/**
 * Exercise real 100/101/1,000-file runs outside routine Pest suites.
 *
 * This file intentionally lives in tests/Stress, which phpunit.xml does not
 * discover. Invoke it explicitly through ./bin/test.sh once PR 2 is implemented,
 * then wire that command into the existing merge/release Microtext workflow.
 * Do not put a thousand-file Vault run into every pull-request CI invocation.
 */
test('a bounded directory run preserves every independent Item across 100-file pages', function (int $count): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database) use ($count): void {
        $corpus = Pr2Directory::corpus($archive, $count);
        $scope = Pr2Directory::discover($archive, $descriptor, $corpus['source'], $count);

        // This guard fires inside each *real* Worker::do() Demand insert.
        // If more than 100 per-file Assignments are simultaneously active,
        // PostgreSQL rejects admission even if a CLI status receipt lies.
        $database->exec(<<<'SQL'
        CREATE FUNCTION configuration_proof.pr2_enforce_bound() RETURNS trigger LANGUAGE plpgsql AS $$
        DECLARE outstanding integer;
        BEGIN
            IF NEW.payload::jsonb ->> 'sourceId' IS NOT NULL THEN
                -- Serialize concurrent probes for this run before counting committed Jobs.
                PERFORM pg_advisory_xact_lock(hashtext('pr2-outstanding'), hashtext(NEW.payload::jsonb ->> 'runId'));
                SELECT count(DISTINCT a.uuid) INTO outstanding
                FROM configuration_proof.operation_demands d
                JOIN configuration_proof.operation_assignments a ON a.uuid = d.assignment_id
                WHERE a.active
                  AND d.payload::jsonb ->> 'sourceId' IS NOT NULL
                  AND d.payload::jsonb ->> 'runId' = NEW.payload::jsonb ->> 'runId';
                IF outstanding > 100 THEN
                    RAISE EXCEPTION 'PR2 more than 100 outstanding per-file Assignments: %', outstanding;
                END IF;
            END IF;
            RETURN NEW;
        END
        $$;
        CREATE TRIGGER pr2_enforce_bound AFTER INSERT ON configuration_proof.operation_demands
        FOR EACH ROW EXECUTE FUNCTION configuration_proof.pr2_enforce_bound();
        SQL);
        try {
            Pr2Directory::acquire($archive, $descriptor, $scope['run'], $count, $count >= 1_000 ? 1_800 : 600);
        } finally {
            $database->exec('DROP TRIGGER pr2_enforce_bound ON configuration_proof.operation_demands');
            $database->exec('DROP FUNCTION configuration_proof.pr2_enforce_bound()');
        }

        Pr2Directory::assertPerFileAssignments($database, $scope['run'], $count);
        $members = Pr2Directory::members($archive, $descriptor, $scope['stash']);
        expect($members)->toHaveCount($count);
        $original = Pr2Directory::assertPreserved($archive, $members, $corpus['entries']);

        // A second complete scan must preserve stable Item membership and v1 pins.
        $newRun = Pr1RoundTrip::command($archive, $descriptor, ['input:discover', $scope['input']]);
        Pr2Directory::settle($archive, $descriptor, $newRun['assignment_id'], $count >= 1_000 ? 600 : 180);
        Pr2Directory::acquire($archive, $descriptor, $newRun['run_id'], $count, $count >= 1_000 ? 1_800 : 600);
        Pr2Directory::assertPerFileAssignments($database, $newRun['run_id'], $count);
        expect(Pr2Directory::members($archive, $descriptor, $scope['stash']))->toBe($members);
        expect(Pr2Directory::assertPreserved($archive, $members, $corpus['entries']))->toBe($original);

        // Delete the only source root before selecting and publishing.
        $archive->destroy('inputs');
        $first = Pr2Directory::broadcast($archive, $descriptor, $scope['stash'], $count);
        expect(Pr1RoundTrip::verify($corpus['manifest'], $archive->path('output')))->toBe(0);
        $inodes = Pr2Directory::inodes($archive->path('output'), $corpus['entries']);

        // Repeating a frozen selection must not replace already correct outputs.
        Pr2Directory::broadcast($archive, $descriptor, $scope['stash'], $count, $first['broadcast']);
        expect(Pr1RoundTrip::verify($corpus['manifest'], $archive->path('output')))->toBe(0);
        expect(Pr2Directory::inodes($archive->path('output'), $corpus['entries']))->toBe($inodes);
    });
})->with([
    'exactly one full admission page' => [100],
    'one file across the 100-item boundary' => [101],
    'ten full pages in the isolated stress runner' => [1_000],
]);
