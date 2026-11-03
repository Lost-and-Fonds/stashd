<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\Integration\Pr1RoundTrip;
use Tests\Support\RecordsAndArchives\ArchiveFixture;
use Tests\Support\RecordsAndArchives\OcflInspector;

require_once dirname(__DIR__, 2) . '/Support/Integration/configuration-cli.php';

/**
 * Publish the actual Stash selection without building an archival pin list in tests.
 *
 * This command is the deliberately small proposed public seam for PR 1:
 * Stash membership, not test-authored SQL or hard-coded OCFL object IDs, chooses
 * the exact preserved revision and the existing coordinator freezes its pins.
 */
function pr1_broadcast(ArchiveFixture $archive, array $descriptor, string $stashId): string
{
    $broadcast = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:create', 'filesystem', $archive->path('output')]);
    $frozen = Pr1RoundTrip::command($archive, $descriptor, ['stash:broadcast:select', $stashId, $broadcast['id']]);
    expect($frozen['id'] ?? null)->not->toBeNull();
    $admitted = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:build', $frozen['id']]);
    expect($admitted['assignment_id'] ?? null)->not->toBeNull('Broadcast must be admitted through Operations.');
    Pr1RoundTrip::settle($archive, $descriptor, $admitted['assignment_id']);
    $published = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:publication', $frozen['id']]);
    expect($published['run']['status'])->toBe('complete');
    expect($published['results'])->toHaveCount(1);
    expect($published['results'][0]['kind'])->toBe('file');

    return $frozen['id'];
}

test('one Microtext Item travels through Stash, real Operations, the Vault and source-independent Broadcast', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $corpus = Pr1RoundTrip::microtext($archive);
        expect(Pr1RoundTrip::verify($corpus['manifest'], $corpus['source']))->toBe(0);
        $scope = Pr1RoundTrip::discover($archive, $descriptor, $corpus['source']);
        $discovery = Pr1RoundTrip::command($archive, $descriptor, ['input:discovery', $scope['run']]);
        expect($discovery['status'])->toBe('finished')
            ->and($discovery['coverage']['kind'])->toBe('exhaustive')
            ->and($discovery['discovered_count'])->toBe(1);

        // A repeated active request must coalesce without inventing another execution.
        $assignment = Pr1RoundTrip::acquire($archive, $descriptor, $scope['run']);
        expect(Pr1RoundTrip::acquire($archive, $descriptor, $scope['run']))->toBe($assignment);
        Pr1RoundTrip::settle($archive, $descriptor, $assignment);

        $item = Pr1RoundTrip::preserved($archive, $scope['item']['object_id'], $corpus['bytes']);
        expect($item['item']['uri'])->toBe($scope['item']['uri']);
        $membership = Pr1RoundTrip::command($archive, $descriptor, ['stash:items', $scope['stash']]);
        expect($membership['items'])->toHaveCount(1)
            ->and($membership['items'][0]['uri'])->toBe($scope['item']['uri']);

        // Remove A *before* asking the application to select and publish from Vault.
        $archive->destroy('inputs');
        pr1_broadcast($archive, $descriptor, $scope['stash']);
        expect(Pr1RoundTrip::verify($corpus['manifest'], $archive->path('output')))->toBe(0);

        // Independent negative control: even a completed Job cannot turn damaged
        // OCFL content into genuinely preserved data.
        expect(Pr1RoundTrip::command($archive, $descriptor, ['operations:inspect', $assignment])['state'])->toBe('completed');
        file_put_contents($item['payload'], 'deliberately corrupted archival bytes');
        $caught = false;
        try {
            Pr1RoundTrip::preserved($archive, $scope['item']['object_id'], $corpus['bytes']);
        } catch (AssertionFailedError $failure) {
            $caught = true;
        }
        expect($caught)->toBeTrue('Physical corruption must be detected independently of completed Job state.');
    });
});

test('killing the real worker after Vault commit but before result acceptance recovers without source or duplicate versions', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $corpus = Pr1RoundTrip::microtext($archive);
        $scope = Pr1RoundTrip::discover($archive, $descriptor, $corpus['source']);
        $assignment = Pr1RoundTrip::acquire($archive, $descriptor, $scope['run']);

        // This pause is in PostgreSQL's real result-insert path, not a test-only
        // handler or fake transaction. It forces a process-crash window after the
        // canonical Vault bytes exist but before Operations accepts the Result.
        $baseline = (int) $database->query('SELECT count(*) FROM configuration_proof.operation_results')->fetchColumn();
        $database->exec(<<<'SQL'
CREATE FUNCTION configuration_proof.pr1_pause_result() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM pg_advisory_xact_lock(7185, 85);
    RETURN NEW;
END
$$;
CREATE TRIGGER pr1_pause_result BEFORE INSERT ON configuration_proof.operation_results
FOR EACH ROW EXECUTE FUNCTION configuration_proof.pr1_pause_result();
SQL);
        $database->query('SELECT pg_advisory_lock(7185, 85)');
        $child = start_configuration_cli($archive, $descriptor, ['operations:work', '--once']);
        $atBoundary = false;
        $before = null;

        try {
            $deadline = microtime(true) + 15;
            do {
                $waiting = (int) $database->query(
                    "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND classid = 7185 AND objid = 85
                    AND NOT granted AND database = (SELECT oid FROM pg_database WHERE datname = current_database())",
                )->fetchColumn();
                if ($waiting === 1) {
                    $atBoundary = true;
                    break;
                }

                $status = proc_get_status($child['process']);
                if (! $status['running']) {
                    break;
                }

                usleep(20_000);
            } while (microtime(true) < $deadline);

            expect($atBoundary)->toBeTrue('Worker did not reach the blocked result write after physical publication.');
            $before = Pr1RoundTrip::preserved($archive, $scope['item']['object_id'], $corpus['bytes']);
            expect((int) $database->query('SELECT count(*) FROM configuration_proof.operation_results')->fetchColumn())->toBe($baseline);
            $archive->destroy('inputs');
        } finally {
            $running = proc_get_status($child['process'])['running'];
            if ($running) {
                proc_terminate($child['process'], 9);
            }
            finish_configuration_cli($child);
            $database->query('SELECT pg_advisory_unlock(7185, 85)');
            $database->exec('DROP TRIGGER IF EXISTS pr1_pause_result ON configuration_proof.operation_results');
            $database->exec('DROP FUNCTION IF EXISTS configuration_proof.pr1_pause_result()');
        }

        expect($before)->not->toBeNull();
        Pr1RoundTrip::settle($archive, $descriptor, $assignment);
        expect((int) $database->query('SELECT count(*) FROM configuration_proof.operation_results')->fetchColumn())->toBe($baseline + 1);
        $after = Pr1RoundTrip::preserved($archive, $scope['item']['object_id'], $corpus['bytes']);
        expect($after['pin'])->toBe($before['pin']);
        expect(hash_file('sha256', $after['payload']))->toBe($corpus['digest']);

        // The recovered execution must support the same source-independent output
        // even though its source disappeared *before* acknowledgement and retry.
        pr1_broadcast($archive, $descriptor, $scope['stash']);
        expect(Pr1RoundTrip::verify($corpus['manifest'], $archive->path('output')))->toBe(0);

        $itemVersions = OcflInspector::inventoryFor($archive->path('vault'), $scope['item']['object_id'])['inventory']['versions'];
        $assetVersions = OcflInspector::inventoryFor($archive->path('vault'), $after['pin']['object_id'])['inventory']['versions'];
        expect(array_keys($itemVersions))->toBe(['v1'])->and(array_keys($assetVersions))->toBe(['v1']);
    });
});
