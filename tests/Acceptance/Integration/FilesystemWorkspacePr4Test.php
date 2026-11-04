<?php

declare(strict_types=1);

use Tests\Support\Integration\Pr1RoundTrip;
use Tests\Support\Integration\Pr2Directory;
use Tests\Support\Integration\Pr4Recovery;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

require_once dirname(__DIR__, 2) . '/Support/Integration/configuration-cli.php';

/**
 * Leave genuine application-owned workspace evidence after a real rename and SIGKILL.
 * All returned identities come from persisted Jobs and the application's own files.
 */
function pr4_interrupted_publication(ArchiveFixture $archive, array $descriptor, PDO $database): array
{
    $corpus = Pr2Directory::corpus($archive, 3);
    $scope = Pr2Directory::discover($archive, $descriptor, $corpus['source'], 3);
    Pr2Directory::acquire($archive, $descriptor, $scope['run'], 3);
    $history = Pr2Directory::assertPreserved($archive, Pr2Directory::members($archive, $descriptor, $scope['stash']), $corpus['entries']);
    $destination = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:create', 'filesystem', $archive->path('output')]);
    $run = Pr1RoundTrip::command($archive, $descriptor, ['stash:broadcast:select', $scope['stash'], $destination['id']]);
    $assignment = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:build', $run['id']])['assignment_id'];
    $archive->destroy('inputs');
    $database->exec(<<<'SQL'
    CREATE FUNCTION configuration_proof.pr4_workspace_report() RETURNS trigger LANGUAGE plpgsql AS $$
    BEGIN PERFORM pg_advisory_xact_lock(7195, 2); RETURN NEW; END $$;
    CREATE TRIGGER pr4_workspace_report BEFORE INSERT ON configuration_proof.broadcast_reports
    FOR EACH ROW EXECUTE FUNCTION configuration_proof.pr4_workspace_report();
    SQL);
    $database->query('SELECT pg_advisory_lock(7195, 2)');
    $child = start_configuration_cli($archive, $descriptor, ['operations:work', '--once']);
    try {
        Pr4Recovery::awaitLock($database, $child, 7195, 2);
        $workspaces = glob($archive->path('output/.stashd-publication-*'), GLOB_ONLYDIR);
        expect($workspaces)->toHaveCount(1);
        $workspace = $workspaces[0];
        $receipts = glob($workspace . '.*.owner');
        expect($receipts)->toHaveCount(1, 'Reclamation requires durable authenticated ownership, not a filename guess.');
        $first = [];
        foreach ($corpus['entries'] as $relative => $entry) {
            if (! is_file($archive->path('output/' . $relative))) {
                continue;
            }

            expect(hash_file('sha256', $archive->path('output/' . $relative)))->toBe($entry['sha256']);
            $first[$relative] = fileinode($archive->path('output/' . $relative));
        }

        expect($first)->toHaveCount(1);
        expect(Pr4Recovery::results($database, $assignment))->toBe([]);
    } finally {
        Pr4Recovery::kill($child);
        $database->query('SELECT pg_advisory_unlock(7195, 2)');
        $database->exec('DROP TRIGGER pr4_workspace_report ON configuration_proof.broadcast_reports');
        $database->exec('DROP FUNCTION configuration_proof.pr4_workspace_report()');
    }

    return compact('corpus', 'scope', 'history', 'destination', 'run', 'assignment', 'workspace', 'receipts', 'first');
}

/**
 * Kill a genuine successor after it has unlinked part of the abandoned workspace.
 * A later successor must finish reclamation, not lose ownership or touch final files.
 */
test('a second SIGKILL during workspace reclamation is recoverable and detects new obstructions', function (bool $obstruct, bool $afterDirectory): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database) use ($obstruct, $afterDirectory): void {
        $proof = pr4_interrupted_publication($archive, $descriptor, $database);
        $before = glob($proof['workspace'] . '/*');
        expect(count($before))->toBeGreaterThan(1);
        Pr4Recovery::expireFixtureDelivery($database);
        $database->query('SELECT pg_advisory_lock(7195, 1)');
        $child = start_configuration_cli(
            $archive,
            $descriptor,
            ['operations:work', '--once'],
            [
                'PR4_WORKSPACE' => $proof['workspace'],
                'PR4_ENTRY_COUNT' => $afterDirectory ? '-1' : (string) count($before),
            ],
            'tests/Support/Integration/workspace-worker.php',
        );
        try {
            Pr4Recovery::awaitLock($database, $child, 7195, 1);
            $remaining = glob($proof['workspace'] . '/*');
            if ($afterDirectory) {
                expect(is_dir($proof['workspace']))->toBeFalse();
                expect($remaining)->toBe([]);
            } else {
                expect(count($remaining))->toBeGreaterThan(0)->toBeLessThan(count($before));
            }

            expect(is_file($proof['receipts'][0]))->toBeTrue('Ownership must outlive the files being reclaimed.');
            expect(Pr4Recovery::results($database, $proof['assignment']))->toBe([]);
        } finally {
            Pr4Recovery::kill($child);
            $database->query('SELECT pg_advisory_unlock(7195, 1)');
        }

        if ($obstruct) {
            $foreign = $proof['workspace'] . '/operator-owned';
            file_put_contents($foreign, 'retain this obstruction');
            $inode = fileinode($foreign);
            Pr4Recovery::expireFixtureDelivery($database);
            $worker = configuration_cli($archive, $descriptor, ['operations:work', '--once']);
            expect($worker['code'])->toBe(0, $worker['output'] . $worker['errors']);
            expect(Pr4Recovery::results($database, $proof['assignment']))->toBe([]);
            $expected = [...$remaining, $foreign];
            sort($expected);
            expect(glob($proof['workspace'] . '/*'))->toBe($expected);
            expect(file_get_contents($foreign))->toBe('retain this obstruction');
            expect(fileinode($foreign))->toBe($inode);
            expect(is_file($proof['receipts'][0]))->toBeTrue();
            unlink($foreign);
            $proof['assignment'] = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:build', $proof['run']['id']])['assignment_id'];
        } else {
            Pr4Recovery::expireFixtureDelivery($database);
        }

        Pr2Directory::settle($archive, $descriptor, $proof['assignment'], 120);
        expect(Pr1RoundTrip::verify($proof['corpus']['manifest'], $archive->path('output')))->toBe(0);
        expect(glob($archive->path('output/.stashd-publication-*')))->toBe([]);
        foreach ($proof['first'] as $relative => $inode) {
            expect(fileinode($archive->path('output/' . $relative)))->toBe($inode);
        }

        expect(Pr4Recovery::results($database, $proof['assignment']))->toHaveCount(1);
        expect(Pr2Directory::assertPreserved($archive, Pr2Directory::members($archive, $descriptor, $proof['scope']['stash']), $proof['corpus']['entries']))
            ->toBe($proof['history']);
    });
})->with([
    'resume interrupted cleanup' => [false, false],
    'retain an obstruction introduced after cleanup death' => [true, false],
    'resume after rmdir but before removing ownership' => [false, true],
]);

/**
 * A genuine receipt is not transferable to another run, root or directory inode.
 * Unknown entries and links inside an owned directory must obstruct all cleanup.
 */
test('workspace reclamation rejects unauthenticated or contradictory ownership and unsafe contents', function (string $fault): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database) use ($fault): void {
        $proof = pr4_interrupted_publication($archive, $descriptor, $database);
        $assignment = $proof['assignment'];
        $receipt = $proof['receipts'][0];
        $outside = $archive->path('outside-sentinel');
        file_put_contents($outside, 'outside bytes must survive');
        $outsideInode = fileinode($outside);
        if ($fault === 'unknown entry') {
            file_put_contents($proof['workspace'] . '/.operator-owned', 'retain unknown private material');
        } elseif ($fault === 'linked entry') {
            symlink($outside, $proof['workspace'] . '/bytes');
        } elseif ($fault === 'replaced directory') {
            rename($proof['workspace'], $archive->path('original-workspace'));
            mkdir($proof['workspace'], 0o700);
            file_put_contents($proof['workspace'] . '/bytes', 'operator-owned replacement directory');
        } elseif ($fault === 'changed root') {
            rename($archive->path('output'), $archive->path('original-output'));
            mkdir($archive->path('output'), 0o700);
            rename($archive->path('original-output/' . basename($proof['workspace'])), $proof['workspace']);
            rename($archive->path('original-output/' . basename($receipt)), $receipt);
        } elseif ($fault === 'wrong run') {
            $other = Pr1RoundTrip::command($archive, $descriptor, ['stash:broadcast:select', $proof['scope']['stash'], $proof['destination']['id']]);
            $assignment = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:build', $other['id']])['assignment_id'];
            expect($other['id'])->not->toBe($proof['run']['id']);
        } else {
            $parts = explode('.', $receipt);
            $parts[count($parts) - 2] = str_repeat('0', 64);
            $forged = implode('.', $parts);
            rename($receipt, $forged);
            $receipt = $forged;
        }

        $snapshot = static function () use ($proof): array {
            $entries = [];
            foreach (new DirectoryIterator($proof['workspace']) as $entry) {
                if ($entry->isDot()) {
                    continue;
                }

                clearstatcache(true, $entry->getPathname());
                $stat = lstat($entry->getPathname());
                $entries[$entry->getFilename()] = [
                    'identity' => array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'])),
                    'content' => $entry->isLink() ? readlink($entry->getPathname()) : hash_file('sha256', $entry->getPathname()),
                ];
            }

            ksort($entries);

            return $entries;
        };
        $before = $snapshot();
        $ownerInode = fileinode($receipt);
        if ($fault !== 'wrong run') {
            Pr4Recovery::expireFixtureDelivery($database);
        }

        $worker = configuration_cli($archive, $descriptor, ['operations:work', '--once']);
        expect($worker['code'])->toBe(0, $worker['output'] . $worker['errors']);
        expect(Pr4Recovery::results($database, $assignment))->toBe([]);
        expect($snapshot())->toBe($before, 'No owned entry may be deleted before all contents and ownership are checked.');
        expect(fileinode($receipt))->toBe($ownerInode);
        expect(file_get_contents($outside))->toBe('outside bytes must survive');
        expect(fileinode($outside))->toBe($outsideInode);
        $attempts = Pr4Recovery::attempts($database, $assignment);
        expect(count($attempts))->toBeGreaterThan($fault === 'wrong run' ? 0 : 1);
        expect($attempts[array_key_last($attempts)]['outcome'])->toBe('failed');
    });
})->with(['unknown entry', 'linked entry', 'replaced directory', 'changed root', 'wrong run', 'forged receipt']);

/**
 * Interpret configured destination names literally, even when glob would treat them as patterns.
 */
test('workspace discovery does not overlook unowned material in a destination containing brackets', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $proof = pr4_interrupted_publication($archive, $descriptor, $database);
        $root = $archive->path('output[1]');
        mkdir($root, 0o700);
        $workspace = $root . '/.stashd-publication-' . str_repeat('b', 32);
        mkdir($workspace, 0o700);
        file_put_contents($workspace . '/bytes', 'operator-owned material');
        $inode = fileinode($workspace . '/bytes');
        $destination = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:create', 'filesystem', $root]);
        $run = Pr1RoundTrip::command($archive, $descriptor, ['stash:broadcast:select', $proof['scope']['stash'], $destination['id']]);
        $assignment = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:build', $run['id']])['assignment_id'];
        $worker = configuration_cli($archive, $descriptor, ['operations:work', '--once']);
        expect($worker['code'])->toBe(0, $worker['output'] . $worker['errors']);
        expect(Pr4Recovery::results($database, $assignment))->toBe([]);
        expect(file_get_contents($workspace . '/bytes'))->toBe('operator-owned material');
        expect(fileinode($workspace . '/bytes'))->toBe($inode);
        expect(scandir($root))->toBe(['.', '..', basename($workspace)]);
        $attempts = Pr4Recovery::attempts($database, $assignment);
        expect($attempts)->not->toBe([]);
        expect($attempts[array_key_last($attempts)]['outcome'])->toBe('failed');
    });
});

/**
 * Revoke the real publication and claim sessions while their PHP process stays alive.
 * A successor must respect the local workspace lock rather than reclaim live files.
 */
test('a live workspace owner excludes reclamation after its PostgreSQL sessions disappear', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $proof = pr4_interrupted_publication($archive, $descriptor, $database);
        $before = glob($proof['workspace'] . '/*');
        Pr4Recovery::expireFixtureDelivery($database);
        $database->query('SELECT pg_advisory_lock(7195, 1)');
        $owner = start_configuration_cli(
            $archive,
            $descriptor,
            ['operations:work', '--once'],
            [
                'PR4_WORKSPACE' => $proof['workspace'],
                'PR4_ENTRY_COUNT' => '0',
            ],
            'tests/Support/Integration/workspace-worker.php',
        );
        try {
            Pr4Recovery::awaitLock($database, $owner, 7195, 1);
            $attempts = Pr4Recovery::attempts($database, $proof['assignment']);
            expect($attempts)->toHaveCount(2);
            $live = $attempts[1];
            $publication = $database->prepare(<<<'SQL'
            SELECT pid FROM pg_locks WHERE locktype = 'advisory' AND granted
              AND classid = hashtext('configuration_proof')::oid AND objid = hashtext(?)::oid
              AND database = (SELECT oid FROM pg_database WHERE datname = current_database())
            SQL);
            $publication->execute(['broadcast:' . $proof['destination']['id']]);
            $publisher = $publication->fetchColumn();
            expect($publisher)->not->toBeFalse();
            expect((int) $publisher)->not->toBe((int) $live['backend_pid']);
            $revoke = $database->prepare('SELECT pg_terminate_backend(?)');
            foreach ([$publisher, $live['backend_pid']] as $pid) {
                $revoke->execute([$pid]);
                expect($revoke->fetchColumn())->toBeTrue();
            }

            Pr4Recovery::expireFixtureDelivery($database);
            $competitor = configuration_cli($archive, $descriptor, ['operations:work', '--once']);
            expect($competitor['code'])->toBe(0, $competitor['output'] . $competitor['errors']);
            expect(proc_get_status($owner['process'])['running'])->toBeTrue();
            expect(glob($proof['workspace'] . '/*'))->toBe($before);
            expect(is_file($proof['receipts'][0]))->toBeTrue();
            expect(Pr4Recovery::results($database, $proof['assignment']))->toBe([]);
            $after = Pr4Recovery::attempts($database, $proof['assignment']);
            expect(count($after))->toBeGreaterThan(2, 'Actual successor Attempts must try reclamation.');
            expect($after[1]['outcome'])->toBe('interrupted');
            expect($after[array_key_last($after)]['outcome'])->toBe('failed');
        } finally {
            $database->query('SELECT pg_advisory_unlock(7195, 1)');
            try {
                Pr4Recovery::awaitExit($owner, 20);
            } finally {
                Pr4Recovery::kill($owner);
            }
        }

        expect(Pr4Recovery::results($database, $proof['assignment']))->toBe([]);
        $assignment = Pr1RoundTrip::command($archive, $descriptor, ['broadcast:build', $proof['run']['id']])['assignment_id'];
        Pr2Directory::settle($archive, $descriptor, $assignment, 120);
        expect(Pr1RoundTrip::verify($proof['corpus']['manifest'], $archive->path('output')))->toBe(0);
        expect(glob($archive->path('output/.stashd-publication-*')))->toBe([]);
        foreach ($proof['first'] as $relative => $inode) {
            expect(fileinode($archive->path('output/' . $relative)))->toBe($inode);
        }
    });
});
