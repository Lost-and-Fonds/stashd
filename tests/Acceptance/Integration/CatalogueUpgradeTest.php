<?php

declare(strict_types=1);

use Tests\Support\Integration\Pr1RoundTrip;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

require_once dirname(__DIR__, 2) . '/Support/Integration/configuration-cli.php';

/**
 * Exercise the additive upgrade against a populated pre-Catalogue Integration schema.
 * Only disposable fixture tables are reverted; canonical archival history is retained.
 */
test('Catalogue upgrade retains existing Input, source URI, acquisition transaction and archival history', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $corpus = Pr1RoundTrip::microtext($archive);
        Pr1RoundTrip::command($archive, $descriptor, ['vault:init']);
        Pr1RoundTrip::command($archive, $descriptor, ['integration:init']);
        $input = Pr1RoundTrip::command($archive, $descriptor, ['input:create', 'filesystem', $corpus['source']]);
        $scan = Pr1RoundTrip::command($archive, $descriptor, ['input:scan', $input['id']]);
        Pr1RoundTrip::command($archive, $descriptor, ['input:preserve', $scan['id']]);
        $before = $database->query('SELECT id, run_id, source_id, item_uri, status, result FROM configuration_proof.acquisition_attempts')->fetchAll(PDO::FETCH_ASSOC);
        expect($before)->toHaveCount(1);

        // Recreate only the schema state that predates the additive Catalogue migration.
        $database->exec(<<<'SQL'
        ALTER TABLE configuration_proof.acquisition_sources DROP CONSTRAINT acquisition_sources_item_uri_fkey;
        ALTER TABLE configuration_proof.acquisition_sources DROP COLUMN uuid, DROP COLUMN created_at, DROP COLUMN updated_at;
        DROP TABLE configuration_proof.catalogue_stash_items, configuration_proof.catalogue_stash_inputs,
            configuration_proof.catalogue_items, configuration_proof.catalogue_stashes;
        DELETE FROM configuration_proof.migrations WHERE name = '2026-11-03_01_catalogue';
        SQL);
        Pr1RoundTrip::command($archive, $descriptor, ['integration:init']);
        expect($database->query('SELECT id, run_id, source_id, item_uri, status, result FROM configuration_proof.acquisition_attempts')->fetchAll(PDO::FETCH_ASSOC))->toBe($before);

        $stash = Pr1RoundTrip::command($archive, $descriptor, ['stash:create', 'Existing Input']);
        Pr1RoundTrip::command($archive, $descriptor, ['stash:input:attach', $stash['id'], $input['id']]);
        Pr1RoundTrip::command($archive, $descriptor, ['stash:input:attach', $stash['id'], $input['id']]);
        $members = Pr1RoundTrip::command($archive, $descriptor, ['stash:items', $stash['id']]);
        expect($members['items'])->toHaveCount(1)->and($members['items'][0]['uri'])->toBe($before[0]['item_uri']);
        $assignment = Pr1RoundTrip::acquire($archive, $descriptor, $scan['id']);
        $archive->destroy('inputs');
        Pr1RoundTrip::settle($archive, $descriptor, $assignment);
        Pr1RoundTrip::preserved($archive, $members['items'][0]['object_id'], $corpus['bytes']);
    });
});
