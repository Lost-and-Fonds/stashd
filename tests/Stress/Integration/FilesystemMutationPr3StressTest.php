<?php

declare(strict_types=1);

use Tests\Support\Integration\Pr1RoundTrip;
use Tests\Support\Integration\Pr2Directory;
use Tests\Support\Integration\Pr3Mutation;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

require_once dirname(__DIR__, 2) . '/Support/Integration/configuration-cli.php';

/**
 * Run the full separate mutation corpus only on demand, never in routine PR CI.
 *
 * The source starts with 400 generated files, then 100 are added, 200 altered
 * in place, 50 renamed byte-for-byte and 30 removed. A 400-Item historic
 * selection and the 550-Item post-mutation preserved collection are both
 * published AFTER source A has been destroyed. The pinned v1 fixture remains
 * unchanged; this suite uses its own disposable mutation manifest.
 */
test('full 100/200/50/30 mutation corpus retains original and updated historical selections', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $corpus = Pr2Directory::corpus($archive, 400);
        $scope = Pr2Directory::discover($archive, $descriptor, $corpus['source'], 400);
        Pr2Directory::acquire($archive, $descriptor, $scope['run'], 400, 1_800);
        $initial = Pr3Mutation::history($archive, Pr2Directory::members($archive, $descriptor, $scope['stash']));
        expect($initial)->toHaveCount(400);

        $previous = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:create', 'filesystem', $archive->path('historical-output')]);
        $old = Pr1RoundTrip::command($archive, $descriptor, ['stash:broadcast:select', $scope['stash'], $previous['id']]);

        $mutation = Pr3Mutation::mutate($archive, $corpus['manifest'], add: 100, change: 200, rename: 50, remove: 30);
        expect(Pr3Mutation::verify($mutation['source'], $corpus['source']))->toBe(0);
        $secondScan = Pr1RoundTrip::command($archive, $descriptor, ['input:discover', $scope['input']]);
        Pr2Directory::settle($archive, $descriptor, $secondScan['assignment_id'], 600);
        $scanState = Pr1RoundTrip::command($archive, $descriptor, ['input:discovery', $secondScan['run_id']]);
        expect($scanState['coverage']['kind'])->toBe('exhaustive');
        expect($scanState['discovered_count'])->toBe(470);
        Pr2Directory::acquire($archive, $descriptor, $secondScan['run_id'], 470, 1_800);

        $history = Pr3Mutation::history($archive, Pr2Directory::members($archive, $descriptor, $scope['stash']));
        expect($history)->toHaveCount(550);
        $expectedPaths = array_keys($mutation['expected']);
        $observedPaths = array_keys($history);
        sort($expectedPaths);
        sort($observedPaths);
        expect($observedPaths)->toBe($expectedPaths);
        $changed = array_column($mutation['operations']['changed'], 'path');
        foreach ($corpus['entries'] as $relative => $entry) {
            expect($history[$relative]['item'])->toBe($initial[$relative]['item']);
            expect($history[$relative]['asset'])->toBe($initial[$relative]['asset']);
            expect($history[$relative]['hashes']['v1'])->toBe($entry['sha256']);
            expect($history[$relative]['versions'])->toBe(in_array($relative, $changed, true) ? ['v1', 'v2'] : ['v1']);
        }

        foreach ($history as $relative => $record) {
            $version = $record['versions'][array_key_last($record['versions'])];
            expect($record['hashes'][$version])->toBe($mutation['expected'][$relative]['sha256']);
        }

        foreach ($mutation['operations']['renamed'] as $renamed) {
            expect($history[$renamed['to']]['item'])->not->toBe($history[$renamed['from']]['item']);
            expect($history[$renamed['to']]['hashes']['v1'])->toBe($history[$renamed['from']]['hashes']['v1']);
        }

        $next = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:create', 'filesystem', $archive->path('current-output')]);
        $current = Pr1RoundTrip::command($archive, $descriptor, ['stash:broadcast:select', $scope['stash'], $next['id']]);
        $oldVersions = [];
        foreach ($initial as $item) {
            $oldVersions[$item['item']] = 'v1';
        }

        $currentVersions = [];
        foreach ($history as $item) {
            $currentVersions[$item['item']] = $item['versions'][array_key_last($item['versions'])];
        }

        Pr3Mutation::assertFrozen($database, $old['id'], $oldVersions);
        Pr3Mutation::assertFrozen($database, $current['id'], $currentVersions);
        $archive->destroy('inputs');
        Pr3Mutation::publish($archive, $descriptor, $old['id'], 400);
        Pr3Mutation::publish($archive, $descriptor, $current['id'], 550);
        expect(Pr1RoundTrip::verify($corpus['manifest'], $archive->path('historical-output')))->toBe(0);
        expect(Pr3Mutation::verify($mutation['preserved'], $archive->path('current-output')))->toBe(0);
    });
});
