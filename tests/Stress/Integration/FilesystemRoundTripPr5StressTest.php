<?php

declare(strict_types=1);

use Tests\Support\Integration\Pr1RoundTrip;
use Tests\Support\Integration\Pr2Directory;
use Tests\Support\Integration\Pr5Scale;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

require_once dirname(__DIR__, 2) . '/Support/Integration/configuration-cli.php';

/**
 * Run the genuine application round trip with the frozen external Microtext oracle.
 *
 * This is deliberately outside phpunit.xml's ordinary test suites. Only the
 * explicitly invoked stress workflow may run the 10,000-Item scenario.
 * No test copies source bytes directly into the Broadcast destination.
 */
test('measured directory workflow preserves and republishes every independent Item from the Vault', function (int $count): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database) use ($count): void {
        $metrics = new Pr5Scale($archive, $database, $count);
        try {
            $corpus = Pr2Directory::corpus($archive, $count);
            $metrics->checkpoint('fixture_generated', ['expected_files' => $count]);
            expect(Pr5Scale::verify($corpus['manifest'], $corpus['source'], $count))->toBe(0);
            $metrics->checkpoint('source_fixture_verified');

            $scope = Pr2Directory::discover($archive, $descriptor, $corpus['source'], $count, fn () => $metrics->checkpoint('discovery_worker_settled'));
            $metrics->checkpoint('discovery_complete', ['discovered' => $count]);

            $empty = configuration_cli($archive, $descriptor, ['operations:work', '--once', '--limit=100']);
            expect($empty['code'])->toBe(0, $empty['output'] . $empty['errors']);
            $metrics->checkpoint('empty_worker_baseline');

            Pr5Scale::acquire($archive, $descriptor, $scope['run'], $count, $metrics);
            $metrics->checkpoint('first_acquisition_settled');
            Pr2Directory::assertPerFileAssignments($database, $scope['run'], $count);
            $members = Pr2Directory::members($archive, $descriptor, $scope['stash']);
            expect($members)->toHaveCount($count);
            $preserved = Pr2Directory::assertPreserved($archive, $members, $corpus['entries']);
            $metrics->checkpoint('first_preservation_verified', ['items' => count($members), 'assets' => count($preserved)]);

            // Repeat the real scan and acquisition before taking source A offline.
            $repeat = Pr1RoundTrip::command($archive, $descriptor, ['input:discover', $scope['input']]);
            Pr2Directory::settle($archive, $descriptor, $repeat['assignment_id'], 3_600);
            $metrics->checkpoint('unchanged_discovery_complete');
            $secondDiscovery = Pr1RoundTrip::command($archive, $descriptor, ['input:discovery', $repeat['run_id']]);
            expect($secondDiscovery['coverage']['kind'])->toBe('exhaustive');
            expect($secondDiscovery['discovered_count'])->toBe($count);
            Pr5Scale::acquire($archive, $descriptor, $repeat['run_id'], $count, $metrics);
            $metrics->checkpoint('unchanged_acquisition_settled');
            Pr2Directory::assertPerFileAssignments($database, $repeat['run_id'], $count);
            expect(Pr2Directory::members($archive, $descriptor, $scope['stash']))->toBe($members);
            expect(Pr2Directory::assertPreserved($archive, $members, $corpus['entries']))
                ->toBe($preserved, 'An unchanged rescan must not create new Item/Asset revisions.');
            $metrics->checkpoint('unchanged_rescan_verified', ['items' => $count, 'assets' => $count]);

            $archive->destroy('inputs');
            expect(is_dir($corpus['source']))->toBeFalse('Input A must be unavailable before publication starts.');
            $metrics->checkpoint('source_removed');

            $first = Pr5Scale::broadcast($archive, $descriptor, $scope['stash'], $count, metrics: $metrics);
            $metrics->checkpoint('first_broadcast_settled', ['reported_files' => $count]);
            expect(Pr5Scale::verify($corpus['manifest'], $archive->path('output'), $count))->toBe(0);
            $inodes = Pr2Directory::inodes($archive->path('output'), $corpus['entries']);
            $metrics->checkpoint('source_independent_broadcast_verified', ['reported_files' => $count]);

            // Rebuild the same selection through Operations, not a direct filesystem copy.
            Pr5Scale::broadcast($archive, $descriptor, $scope['stash'], $count, $first['broadcast'], $metrics);
            $metrics->checkpoint('repeat_broadcast_settled', ['reported_files' => $count]);
            expect(Pr5Scale::verify($corpus['manifest'], $archive->path('output'), $count))->toBe(0);
            expect(Pr2Directory::inodes($archive->path('output'), $corpus['entries']))->toBe($inodes);
            $metrics->checkpoint('repeat_broadcast_verified', ['reported_files' => $count]);

            // The external checker must reject damage even if all application
            // Assignments and Reports have already completed successfully.
            $firstPath = array_key_first($corpus['entries']);
            expect($firstPath)->not->toBeNull();
            file_put_contents($archive->path('output/' . $firstPath), "deliberately altered output\n");
            expect(Pr5Scale::verify($corpus['manifest'], $archive->path('output'), $count))->not->toBe(0, 'Corruption must defeat the independent external verifier.');
            $metrics->checkpoint('negative_control_rejected');

            $metrics->finish($archive->path('vault'));
        } catch (Throwable $error) {
            $metrics->checkpoint('failed');
            $metrics->finish($archive->path('vault'), $error::class);
            throw $error;
        } finally {
            $metrics->close();
        }
    });
})->with([
    '100 Items' => [100],
    '1,000 Items' => [1_000],
    'pinned 10,000 Items' => [10_000],
]);
