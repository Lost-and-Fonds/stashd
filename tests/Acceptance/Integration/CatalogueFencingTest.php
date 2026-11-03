<?php

declare(strict_types=1);

use Tests\Support\Integration\Pr1RoundTrip;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

require_once dirname(__DIR__, 2) . '/Support/Integration/configuration-cli.php';

/**
 * Revoke a real discovery worker while its Catalogue insert is inside PostgreSQL.
 * The successor must recover existing discovery and archival identities without stale writes.
 */
test('discovery rolls back Catalogue mutations when its claim is revoked during SQL', function (string $table, bool $restore): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database) use ($table, $restore): void {
        $corpus = Pr1RoundTrip::microtext($archive);
        Pr1RoundTrip::command($archive, $descriptor, ['vault:init']);
        Pr1RoundTrip::command($archive, $descriptor, ['integration:init']);
        $input = Pr1RoundTrip::command($archive, $descriptor, ['input:create', 'filesystem', $corpus['source']]);
        $uri = null;
        if ($restore) {
            $scan = Pr1RoundTrip::command($archive, $descriptor, ['input:scan', $input['id']]);
            Pr1RoundTrip::command($archive, $descriptor, ['input:preserve', $scan['id']]);
            $uri = $database->query('SELECT item_uri FROM configuration_proof.acquisition_sources')->fetchColumn();
            $database->exec('DELETE FROM configuration_proof.acquisition_sources; DELETE FROM configuration_proof.catalogue_items');
        }

        $stash = Pr1RoundTrip::command($archive, $descriptor, ['stash:create', 'Revocation proof']);
        Pr1RoundTrip::command($archive, $descriptor, ['stash:input:attach', $stash['id'], $input['id']]);
        $admitted = Pr1RoundTrip::command($archive, $descriptor, ['input:discover', $input['id']]);
        // Table names come only from this test's fixed dataset. Pause after the insert,
        // so losing the session must roll back data that was actually written.
        $database->exec(<<<SQL
        CREATE FUNCTION configuration_proof.pause_catalogue() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            PERFORM pg_advisory_xact_lock(7187, 87);
            RETURN NEW;
        END
        $$;
        CREATE TRIGGER pause_catalogue AFTER INSERT ON configuration_proof.{$table}
        FOR EACH ROW EXECUTE FUNCTION configuration_proof.pause_catalogue();
        SQL);
        $database->query('SELECT pg_advisory_lock(7187, 87)');
        $child = start_configuration_cli($archive, $descriptor, ['operations:work', '--once']);
        try {
            $deadline = microtime(true) + 20;
            do {
                $waiting = (int) $database
                    ->query(
                        "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND classid = 7187 AND objid = 87 AND NOT granted AND database = (SELECT oid FROM pg_database WHERE datname = current_database())",
                    )
                    ->fetchColumn();
                if ($waiting === 1 || ! proc_get_status($child['process'])['running']) {
                    break;
                }

                usleep(20_000);
            } while (microtime(true) < $deadline);

            expect($waiting)->toBe(1, 'The real worker must reach the Catalogue insert barrier.');
            if ($table === 'catalogue_stash_items') {
                $uri = $database->query('SELECT item_uri FROM configuration_proof.acquisition_sources')->fetchColumn();
            }

            $pid = (int) $database->query("SELECT backend_pid FROM configuration_proof.operation_attempts WHERE outcome = 'running'")->fetchColumn();
            expect($pid)->toBeGreaterThan(0);
            expect($database->query('SELECT pg_terminate_backend(' . $pid . ', 5000)')->fetchColumn())->toBeTrue();
        } finally {
            $database->query('SELECT pg_advisory_unlock(7187, 87)');
            finish_configuration_cli($child);
            $database->exec('DROP TRIGGER pause_catalogue ON configuration_proof.' . $table);
            $database->exec('DROP FUNCTION configuration_proof.pause_catalogue()');
        }

        expect((int) $database->query('SELECT count(*) FROM configuration_proof.catalogue_stash_items')->fetchColumn())->toBe(0);
        expect((int) $database->query('SELECT count(*) FROM configuration_proof.operation_results')->fetchColumn())->toBe(0);
        $expectedSources = $table === 'catalogue_stash_items' ? 1 : 0;
        expect((int) $database->query('SELECT count(*) FROM configuration_proof.acquisition_sources')->fetchColumn())->toBe($expectedSources);
        expect((int) $database->query('SELECT count(*) FROM configuration_proof.catalogue_items')->fetchColumn())->toBe($expectedSources);

        // Only accelerate the disposable test transport after the old worker exits.
        $database->exec("UPDATE configuration_proof.messenger_messages SET delivered_at = CURRENT_TIMESTAMP - INTERVAL '61 seconds' WHERE delivered_at IS NOT NULL");
        Pr1RoundTrip::settle($archive, $descriptor, $admitted['assignment_id']);
        expect((int) $database->query('SELECT count(*) FROM configuration_proof.operation_results')->fetchColumn())->toBe(1);
        expect($database->query('SELECT outcome FROM configuration_proof.operation_attempts ORDER BY started_at')->fetchAll(PDO::FETCH_COLUMN))->toBe(['interrupted', 'completed']);
        $members = Pr1RoundTrip::command($archive, $descriptor, ['stash:items', $stash['id']]);
        expect($members['items'])->toHaveCount(1);
        if ($uri !== null) {
            expect($members['items'][0]['uri'])->toBe($uri);
        }

        if ($restore) {
            Pr1RoundTrip::preserved($archive, $members['items'][0]['object_id'], $corpus['bytes']);
        }
    });
})->with([
    'new Item and source identity' => ['acquisition_sources', false],
    'Stash membership' => ['catalogue_stash_items', false],
    'identity restored from canonical provenance' => ['acquisition_sources', true],
]);
