<?php

use Tests\Support\RecordsAndArchives\ArchiveFixture;
use Tests\Support\RecordsAndArchives\TransactionProbe;

require_once dirname(__DIR__, 2) . '/Support/Integration/configuration-cli.php';

/**
 * Read all CLI result pages and check that each page advances to a new source ID.
 *
 * Only the test collects every file to compare with its fixture. The application
 * reads one page at a time instead of loading the whole scan into memory.
 */
function discovered_items(ArchiveFixture $archive, array $descriptor, string $runId): array
{
    $items = [];
    $after = '';
    do {
        $page = configuration_result(configuration_cli($archive, $descriptor, ['input:discoveries', $runId, $after]));
        expect(count($page['items']))->toBeLessThanOrEqual(100);
        array_push($items, ...$page['items']);
        $next = $page['next'];
        if ($next !== null) {
            expect($next)->not->toBe($after);
            $after = $next;
        }
    } while ($next !== null);

    return $items;
}

test('manual CLI discovery keeps stable associations and observes rescans without preserving or modifying bytes', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $manifest = ['one/same.bin' => "binary\0\xff", 'two/same.bin' => 'different', '資料 with spaces/<em>empty' => '', '.hidden' => 'hidden'];
        for ($index = 0; $index < 205; $index++) {
            $manifest[sprintf('file-%03d.dat', $index)] = 'contents-' . $index;
        }

        foreach ($manifest as $path => $bytes) {
            $archive->input($path, $bytes);
        }

        $root = $archive->path('inputs');
        $beforeVault = TransactionProbe::snapshot($archive->path('vault'));
        $beforeSource = TransactionProbe::snapshot($root);
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        $input = configuration_result(configuration_cli($archive, $descriptor, ['input:create', 'filesystem', $root]));
        $run = configuration_result(configuration_cli($archive, $descriptor, ['input:discover', $input['id']]));
        expect($run['status'])
            ->toBe('finished')
            ->and($run['coverage'])
            ->toBe(['kind' => 'exhaustive', 'refresh_state' => null])
            ->and($run['continuation'])
            ->toBeNull()
            ->and($run['discovered_count'])
            ->toBe(count($manifest))
            ->and($run['batches'])
            ->toBeGreaterThan(2);
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        expect(configuration_result(configuration_cli($archive, $descriptor, ['input:discovery', $run['id']])))->toBe($run);
        $observations = discovered_items($archive, $descriptor, $run['id']);
        $paths = [];
        foreach ($observations as $item) {
            $reference = json_decode($item['reference'], true, flags: JSON_THROW_ON_ERROR);
            $relative = $reference['path'];
            $paths[] = $relative;
            expect($reference)
                ->toBe(['version' => 1, 'root' => $root, 'path' => $relative])
                ->and($item['id'])
                ->toBe(hash('sha256', $relative))
                ->and($item['size_bytes'])
                ->toBe(strlen($manifest[$relative]))
                ->and($item['size_estimated'])
                ->toBeFalse()
                ->and($item['source'])
                ->toBeNull()
                ->and(json_decode($item['metadata'][0]['json'], true, flags: JSON_THROW_ON_ERROR)['input_id'])
                ->toBe($input['id']);
        }

        sort($paths);
        $expected = array_keys($manifest);
        sort($expected);
        expect($paths)
            ->toBe($expected)
            ->and(TransactionProbe::snapshot($root))
            ->toBe($beforeSource)
            ->and(TransactionProbe::snapshot($archive->path('vault')))
            ->toBe($beforeVault);
        file_put_contents($root . '/one/same.bin', 'modified longer file');
        unlink($root . '/two/same.bin');
        $archive->input('addition.dat', 'new');
        $next = configuration_result(configuration_cli($archive, $descriptor, ['input:discover', $input['id']]));
        expect($next['id'])->not->toBe($run['id'])->and($next['discovered_count'])->toBe(count($manifest));
        $latest = array_column(discovered_items($archive, $descriptor, $next['id']), null, 'id');
        expect($latest[hash('sha256', 'one/same.bin')]['size_bytes'])
            ->toBe(strlen('modified longer file'))
            ->and(isset($latest[hash('sha256', 'two/same.bin')]))
            ->toBeFalse()
            ->and(isset($latest[hash('sha256', 'addition.dat')]))
            ->toBeTrue();
        $rows = $database->query('SELECT source_id, first_run::text, last_run::text FROM configuration_proof.source_associations')->fetchAll(PDO::FETCH_ASSOC);
        $associations = array_column($rows, null, 'source_id');
        expect(count($associations))
            ->toBe(count($manifest) + 1)
            ->and($associations[hash('sha256', 'one/same.bin')])
            ->toMatchArray(['first_run' => $run['id'], 'last_run' => $next['id']])
            ->and($associations[hash('sha256', 'two/same.bin')])
            ->toMatchArray(['first_run' => $run['id'], 'last_run' => $run['id']]);
        $again = configuration_result(configuration_cli($archive, $descriptor, ['input:discover', $input['id']]));
        expect($again['discovered_count'])
            ->toBe($next['discovered_count'])
            ->and((int) $database->query('SELECT count(*) FROM configuration_proof.source_associations')->fetchColumn())
            ->toBe(count($manifest) + 1);
        expect(TransactionProbe::snapshot($archive->path('vault')))->toBe($beforeVault);
        $archive->destroy('inputs');
        expect(configuration_result(configuration_cli($archive, $descriptor, ['input:discovery', $run['id']])))->toBe($run);
    });
});

test('Filesystem discovery reports excluded links names and special files as partial and permits an empty complete scan', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $file = $archive->input('allowed.bin', 'allowed');
        $root = dirname($file);
        file_put_contents($archive->path('outside.bin'), 'outside');
        mkdir($archive->path('outside-dir'));
        file_put_contents($archive->path('outside-dir/private'), 'outside directory');
        symlink($archive->path('outside.bin'), $root . '/link');
        symlink($archive->path('outside-dir'), $root . '/directory-link');
        symlink($archive->path('missing'), $root . '/dangling-link');
        $archive->input("unsupported\nname", 'newline');
        $archive->input("invalid-\xff", 'invalid UTF-8');
        $archive->input('back\\slash', 'backslash');
        $socket = stream_socket_server('unix://' . $root . '/socket', $errorCode, $errorMessage);
        expect($socket)->not->toBeFalse();
        try {
            configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
            $input = configuration_result(configuration_cli($archive, $descriptor, ['input:create', 'filesystem', $root]));
            $run = configuration_result(configuration_cli($archive, $descriptor, ['input:discover', $input['id']]));
            expect($run['status'])->toBe('finished')->and($run['discovered_count'])->toBe(1)->and($run['coverage']['kind'])->toBe('partial');
            $deficiency = $run['coverage']['deficiencies'][0];
            expect($deficiency['disposition'])
                ->toBe('terminal')
                ->and($deficiency['diagnostic']['message'])
                ->toStartWith('7 entries excluded; first:')
                ->and(json_decode($deficiency['diagnostic']['evidence'][0]['json'], true, flags: JSON_THROW_ON_ERROR))
                ->toBe(['skipped' => 7]);
            $items = discovered_items($archive, $descriptor, $run['id']);
            expect(array_column($items, 'id'))
                ->toBe([hash('sha256', 'allowed.bin')])
                ->and(file_get_contents($archive->path('outside.bin')))
                ->toBe('outside')
                ->and((int) $database->query('SELECT count(*) FROM configuration_proof.source_associations')->fetchColumn())
                ->toBe(1);
            mkdir($archive->path('empty'));
            $empty = configuration_result(configuration_cli($archive, $descriptor, ['input:create', 'filesystem', $archive->path('empty')]));
            $emptyRun = configuration_result(configuration_cli($archive, $descriptor, ['input:discover', $empty['id']]));
            expect($emptyRun['discovered_count'])
                ->toBe(0)
                ->and($emptyRun['batches'])
                ->toBe(1)
                ->and($emptyRun['coverage'])
                ->toBe(['kind' => 'exhaustive', 'refresh_state' => null]);
        } finally {
            fclose($socket);
        }
    });
});

test('discovery rechecks live roots and retains a failed run for retry after the source is restored', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $root = dirname($archive->input('document', 'source bytes'));
        $vaultBefore = TransactionProbe::snapshot($archive->path('vault'));
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        $input = configuration_result(configuration_cli($archive, $descriptor, ['input:create', 'filesystem', $root]));
        rename($root, $archive->path('moved'));
        symlink($archive->path('vault'), $root);
        try {
            $failure = configuration_cli($archive, $descriptor, ['input:discover', $input['id']]);
            expect($failure['code'])->not->toBe(0);
            $row = $database->query('SELECT id::text, status, error FROM configuration_proof.discovery_runs')->fetch(PDO::FETCH_ASSOC);
            expect($row['status'])
                ->toBe('running')
                ->and($row['error'])
                ->toContain('symbolic links')
                ->and((int) $database->query('SELECT count(*) FROM configuration_proof.discovery_items')->fetchColumn())
                ->toBe(0);
        } finally {
            unlink($root);
            rename($archive->path('moved'), $root);
        }

        $resumed = configuration_result(configuration_cli($archive, $descriptor, ['input:discover', $input['id']]));
        expect($resumed['id'])
            ->toBe($row['id'])
            ->and($resumed['status'])
            ->toBe('finished')
            ->and($resumed['error'])
            ->toBeNull()
            ->and($resumed['discovered_count'])
            ->toBe(1)
            ->and(TransactionProbe::snapshot($archive->path('vault')))
            ->toBe($vaultBefore);
        $output = $archive->path('output');
        configuration_result(configuration_cli($archive, $descriptor, ['broadcast:create', 'filesystem', $output]));
        $failure = configuration_cli($archive, $descriptor, ['input:discover', $input['id']], ['VAULT_ROOT' => $output]);
        expect($failure['code'])
            ->not
            ->toBe(0)
            ->and($failure['output'] . $failure['errors'])
            ->toContain('must not overlap');
        expect((int) $database->query('SELECT count(*) FROM configuration_proof.discovery_items')->fetchColumn())->toBe(1);
    });
});

test('failed terminal SQL rolls back observations associations and coverage together then retries the same run', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $root = dirname($archive->input('file', 'unchanged'));
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        $input = configuration_result(configuration_cli($archive, $descriptor, ['input:create', 'filesystem', $root]));
        $database->exec(<<<'SQL'
        CREATE FUNCTION configuration_proof.reject_finish() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN RAISE EXCEPTION 'fixture rejects terminal checkpoint'; END $$;
        CREATE TRIGGER reject_finish BEFORE UPDATE ON configuration_proof.discovery_runs
        FOR EACH ROW WHEN (NEW.status = 'finished') EXECUTE FUNCTION configuration_proof.reject_finish();
        SQL);
        $failure = configuration_cli($archive, $descriptor, ['input:discover', $input['id']]);
        expect($failure['code'])->not->toBe(0);
        $run = $database->query('SELECT id::text, status, batches, continuation, coverage, error FROM configuration_proof.discovery_runs')->fetch(PDO::FETCH_ASSOC);
        expect($run['status'])
            ->toBe('running')
            ->and((int) $run['batches'])
            ->toBe(0)
            ->and($run['continuation'])
            ->toBeNull()
            ->and($run['coverage'])
            ->toBeNull()
            ->and((int) $database->query('SELECT count(*) FROM configuration_proof.discovery_items')->fetchColumn())
            ->toBe(0)
            ->and((int) $database->query('SELECT count(*) FROM configuration_proof.source_associations')->fetchColumn())
            ->toBe(0)
            ->and($run['error'])
            ->toContain('fixture rejects terminal checkpoint');
        $database->exec('DROP TRIGGER reject_finish ON configuration_proof.discovery_runs');
        $resumed = configuration_result(configuration_cli($archive, $descriptor, ['input:discover', $input['id']]));
        expect($resumed['id'])->toBe($run['id'])->and($resumed['status'])->toBe('finished')->and($resumed['discovered_count'])->toBe(1);
    });
});

test('a killed real CLI retains its first batch and resumes without duplicate associations', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        for ($index = 0; $index < 205; $index++) {
            $archive->input(sprintf('file-%03d', $index), 'file-' . $index);
        }

        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        $input = configuration_result(configuration_cli($archive, $descriptor, ['input:create', 'filesystem', $archive->path('inputs')]));
        $database->exec(<<<'SQL'
        CREATE FUNCTION configuration_proof.pause_second() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN IF NEW.batches = 2 THEN PERFORM pg_advisory_xact_lock(7861, 28); END IF; RETURN NEW; END $$;
        CREATE TRIGGER pause_second BEFORE UPDATE ON configuration_proof.discovery_runs
        FOR EACH ROW EXECUTE FUNCTION configuration_proof.pause_second();
        SQL);
        $database->query('SELECT pg_advisory_lock(7861, 28)');
        $child = null;
        try {
            $child = start_configuration_cli($archive, $descriptor, ['input:discover', $input['id']]);
            $deadline = microtime(true) + 10;
            do {
                $waiting = (int) $database->query("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND NOT granted
                    AND database = (SELECT oid FROM pg_database WHERE datname = current_database())")->fetchColumn();
                if ($waiting === 1) {
                    break;
                }

                usleep(20_000);
            } while (microtime(true) < $deadline);

            expect($waiting)->toBe(1);
            $checkpoint = $database->query('SELECT id::text, batches, continuation FROM configuration_proof.discovery_runs')->fetch(PDO::FETCH_ASSOC);
            expect((int) $checkpoint['batches'])
                ->toBe(1)
                ->and($checkpoint['continuation'])
                ->not
                ->toBeNull()
                ->and((int) $database->query('SELECT count(*) FROM configuration_proof.discovery_items')->fetchColumn())
                ->toBe(100)
                ->and((int) $database->query('SELECT count(*) FROM configuration_proof.source_associations')->fetchColumn())
                ->toBe(100);
            $competing = configuration_cli($archive, $descriptor, ['input:discover', strtoupper($input['id'])]);
            expect($competing['code'])
                ->not
                ->toBe(0)
                ->and($competing['output'] . $competing['errors'])
                ->toContain('already running');
        } finally {
            if ($child !== null) {
                proc_terminate($child['process'], 9);
                $deadline = microtime(true) + 5;
                do {
                    $status = proc_get_status($child['process']);
                    if ($status['running']) {
                        usleep(10_000);
                    }
                } while ($status['running'] && microtime(true) < $deadline);
            }

            $database->query('SELECT pg_advisory_unlock(7861, 28)');
            if ($child !== null) {
                finish_configuration_cli($child);
            }
        }

        expect($status['signaled'])->toBeTrue()->and($status['termsig'])->toBe(9);
        // Wait for the disconnected PostgreSQL transaction to release its row lock.
        $database->query('SELECT id FROM configuration_proof.discovery_runs FOR UPDATE')->fetchAll();
        expect((int) $database->query('SELECT count(*) FROM configuration_proof.discovery_items')->fetchColumn())
            ->toBe(100)
            ->and((int) $database->query('SELECT count(*) FROM configuration_proof.source_associations')->fetchColumn())
            ->toBe(100);
        $continuation = $database->prepare('UPDATE configuration_proof.discovery_runs SET continuation = ? WHERE id = ?');
        $unsafe = json_decode($checkpoint['continuation'], true, flags: JSON_THROW_ON_ERROR);
        $unsafe['frames'][0]['path'] = '../outside';
        $alias = [...json_decode($checkpoint['continuation'], true, flags: JSON_THROW_ON_ERROR), 'frames.0' => ['path' => '', 'position' => 0]];
        foreach ([$unsafe, $alias] as $invalid) {
            $continuation->execute([json_encode($invalid, JSON_THROW_ON_ERROR), $checkpoint['id']]);
            try {
                $rejected = configuration_cli($archive, $descriptor, ['input:discover', $input['id']]);
                expect($rejected['code'])
                    ->not
                    ->toBe(0)
                    ->and($rejected['output'] . $rejected['errors'])
                    ->toContain('Invalid Filesystem discovery continuation')
                    ->and((int) $database->query('SELECT count(*) FROM configuration_proof.discovery_items')->fetchColumn())
                    ->toBe(100);
            } finally {
                $continuation->execute([$checkpoint['continuation'], $checkpoint['id']]);
            }
        }

        $update = $database->prepare('UPDATE configuration_proof.configurations SET settings = ? WHERE id = ?');
        $update->execute([json_encode(['root' => $archive->path('changed-root')], JSON_THROW_ON_ERROR), $input['id']]);
        try {
            $changed = configuration_cli($archive, $descriptor, ['input:discover', $input['id']]);
            expect($changed['code'])
                ->not
                ->toBe(0)
                ->and($changed['output'] . $changed['errors'])
                ->toContain('changed Input configuration');
        } finally {
            $update->execute([json_encode($input['settings'], JSON_THROW_ON_ERROR), $input['id']]);
        }

        $resumed = configuration_result(configuration_cli($archive, $descriptor, ['input:discover', $input['id']]));
        expect($resumed['id'])
            ->toBe($checkpoint['id'])
            ->and($resumed['status'])
            ->toBe('finished')
            ->and($resumed['discovered_count'])
            ->toBe(205)
            ->and($resumed['batches'])
            ->toBe(3)
            ->and($resumed['continuation'])
            ->toBeNull()
            ->and($resumed['coverage'])
            ->toBe(['kind' => 'exhaustive', 'refresh_state' => null])
            ->and((int) $database->query('SELECT count(*) FROM configuration_proof.source_associations')->fetchColumn())
            ->toBe(205);
        expect(count(discovered_items($archive, $descriptor, $resumed['id'])))->toBe(205);
    });
});
