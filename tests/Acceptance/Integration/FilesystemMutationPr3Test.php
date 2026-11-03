<?php

declare(strict_types=1);

use Tests\Support\Integration\Pr1RoundTrip;
use Tests\Support\Integration\Pr2Directory;
use Tests\Support\Integration\Pr3Mutation;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

require_once dirname(__DIR__, 2) . '/Support/Integration/configuration-cli.php';

/**
 * Preserve two genuine historical snapshots through ordinary Operations Jobs.
 *
 * The old selection is frozen before modification, but BOTH are built only
 * after source A is removed. A publisher that reads latest head for the old
 * selection must fail the unchanged external Microtext manifest.
 */
test('modified paths retain identity while renames and additions create new Items and both frozen histories reconstruct', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $baseline = Pr2Directory::corpus($archive, 9);
        $scope = Pr2Directory::discover($archive, $descriptor, $baseline['source'], 9);
        Pr2Directory::acquire($archive, $descriptor, $scope['run'], 9);
        $initial = Pr3Mutation::history($archive, Pr2Directory::members($archive, $descriptor, $scope['stash']));
        expect($initial)->toHaveCount(9);

        // Freeze an exact-version selection BEFORE updating the source.
        $oldDestination = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:create', 'filesystem', $archive->path('historical-output')]);
        $old = Pr1RoundTrip::command($archive, $descriptor, ['stash:broadcast:select', $scope['stash'], $oldDestination['id']]);
        $oldVersions = [];
        foreach ($initial as $record) {
            $oldVersions[$record['item']] = 'v1';
        }

        Pr3Mutation::assertFrozen($database, $old['id'], $oldVersions);
        $mutation = Pr3Mutation::mutate($archive, $baseline['manifest'], add: 2, change: 3, rename: 2, remove: 1);
        expect(Pr3Mutation::verify($mutation['source'], $baseline['source']))->toBe(0);
        $afterSource = json_decode((string) file_get_contents($mutation['source']), true, flags: JSON_THROW_ON_ERROR);
        expect($afterSource['count'])->toBe(10);

        // The next full scan observes paths, not filenames inferred from content.
        $scan = Pr1RoundTrip::command($archive, $descriptor, ['input:discover', $scope['input']]);
        Pr2Directory::settle($archive, $descriptor, $scan['assignment_id'], 180);
        $observed = Pr1RoundTrip::command($archive, $descriptor, ['input:discovery', $scan['run_id']]);
        expect($observed['coverage']['kind'])->toBe('exhaustive');
        expect($observed['discovered_count'])->toBe($afterSource['count']);
        Pr2Directory::acquire($archive, $descriptor, $scan['run_id'], $afterSource['count']);

        $members = Pr2Directory::members($archive, $descriptor, $scope['stash']);
        expect($members)->toHaveCount(13, 'Old renamed/removed Items are retained; new names and additions get new Items.');
        $history = Pr3Mutation::history($archive, $members);
        $expectedPaths = array_keys($mutation['expected']);
        $archivedPaths = array_keys($history);
        sort($expectedPaths);
        sort($archivedPaths);
        expect($archivedPaths)->toBe($expectedPaths);

        $changed = array_column($mutation['operations']['changed'], 'path');
        foreach ($baseline['entries'] as $relative => $entry) {
            expect($history[$relative]['item'])->toBe($initial[$relative]['item']);
            expect($history[$relative]['uri'])->toBe($initial[$relative]['uri']);
            expect($history[$relative]['asset'])->toBe($initial[$relative]['asset']);
            expect($history[$relative]['hashes']['v1'])->toBe($entry['sha256']);
            if (in_array($relative, $changed, true)) {
                expect($history[$relative]['versions'])->toBe(['v1', 'v2']);
                expect($history[$relative]['hashes']['v2'])->toBe($mutation['expected'][$relative]['sha256']);
            } else {
                expect($history[$relative]['versions'])->toBe(['v1'], 'Unchanged/renamed-away/missing Items must not gain revisions.');
            }
        }

        foreach ($mutation['operations']['renamed'] as $move) {
            expect($history[$move['from']]['hashes']['v1'])->toBe($history[$move['to']]['hashes']['v1']);
            expect($history[$move['from']]['item'])->not->toBe($history[$move['to']]['item']);
            expect($history[$move['from']]['uri'])->not->toBe($history[$move['to']]['uri']);
            expect($history[$move['to']]['versions'])->toBe(['v1']);
        }

        foreach ($mutation['operations']['added'] as $added) {
            expect($history[$added['path']]['versions'])->toBe(['v1']);
        }

        foreach ($mutation['operations']['removed'] as $missing) {
            expect($history[$missing['path']]['versions'])->toBe(['v1']);
            expect($history[$missing['path']]['hashes']['v1'])->toBe($missing['sha256']);
        }

        foreach ($history as $relative => $record) {
            $head = $record['versions'][array_key_last($record['versions'])];
            expect($record['hashes'][$head])->toBe($mutation['expected'][$relative]['sha256']);
        }

        // The second selection uses verified *current* revisions; the first
        // one must still retain v1 for the files changed in this acquisition.
        $newDestination = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:create', 'filesystem', $archive->path('current-output')]);
        $current = Pr1RoundTrip::command($archive, $descriptor, ['stash:broadcast:select', $scope['stash'], $newDestination['id']]);
        $currentVersions = [];
        foreach ($history as $record) {
            $currentVersions[$record['item']] = $record['versions'][array_key_last($record['versions'])];
        }

        Pr3Mutation::assertFrozen($database, $current['id'], $currentVersions);
        Pr3Mutation::assertFrozen($database, $old['id'], $oldVersions);

        $archive->destroy('inputs');
        Pr3Mutation::publish($archive, $descriptor, $old['id'], 9);
        Pr3Mutation::publish($archive, $descriptor, $current['id'], 13);

        expect(Pr1RoundTrip::verify($baseline['manifest'], $archive->path('historical-output')))->toBe(0);
        expect(Pr3Mutation::verify($mutation['preserved'], $archive->path('current-output')))->toBe(0);

        // Negative control: a head-only reader cannot satisfy the original
        // independent manifest, and the historical-only set is not the new set.
        expect(Pr1RoundTrip::verify($baseline['manifest'], $archive->path('current-output')))->not->toBe(0);
        expect(Pr3Mutation::verify($mutation['preserved'], $archive->path('historical-output')))->not->toBe(0);
    });
});

/**
 * A partial scan must not silently declare disappearance, and even an exhaustive
 * later scan must not delete archival history or implicitly prune a Stash.
 */
test('partial and then exhaustive discovery retain missing-source historical Items', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $baseline = Pr2Directory::corpus($archive, 4);
        $scope = Pr2Directory::discover($archive, $descriptor, $baseline['source'], 4);
        Pr2Directory::acquire($archive, $descriptor, $scope['run'], 4);
        $initial = Pr3Mutation::history($archive, Pr2Directory::members($archive, $descriptor, $scope['stash']));
        $lost = array_key_first($baseline['entries']);
        unlink($baseline['source'] . '/' . $lost);
        // Discovery explicitly skips a symbolic link and marks the scan partial;
        // it must not follow the link or treat partial coverage as exhaustive.
        $unsafe = $baseline['source'] . '/partial-scan-link';
        expect(symlink('../directory-manifest.json', $unsafe))->toBeTrue();

        $partial = Pr1RoundTrip::command($archive, $descriptor, ['input:discover', $scope['input']]);
        Pr2Directory::settle($archive, $descriptor, $partial['assignment_id'], 120);
        $coverage = Pr1RoundTrip::command($archive, $descriptor, ['input:discovery', $partial['run_id']]);
        expect($coverage['status'])->toBe('finished');
        expect($coverage['coverage']['kind'])->toBe('partial');
        expect($coverage['discovered_count'])->toBe(3);
        expect(Pr2Directory::members($archive, $descriptor, $scope['stash']))->toHaveCount(4);
        unlink($unsafe);

        $exhaustive = Pr1RoundTrip::command($archive, $descriptor, ['input:discover', $scope['input']]);
        Pr2Directory::settle($archive, $descriptor, $exhaustive['assignment_id'], 120);
        $result = Pr1RoundTrip::command($archive, $descriptor, ['input:discovery', $exhaustive['run_id']]);
        expect($result['coverage']['kind'])->toBe('exhaustive');
        expect($result['discovered_count'])->toBe(3);
        expect(Pr3Mutation::history($archive, Pr2Directory::members($archive, $descriptor, $scope['stash'])))->toBe($initial);

        $destination = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:create', 'filesystem', $archive->path('recovered-output')]);
        $frozen = Pr1RoundTrip::command($archive, $descriptor, ['stash:broadcast:select', $scope['stash'], $destination['id']]);
        $archive->destroy('inputs');
        Pr3Mutation::publish($archive, $descriptor, $frozen['id'], 4);
        expect(Pr1RoundTrip::verify($baseline['manifest'], $archive->path('recovered-output')))->toBe(0);
    });
});
