<?php

use Tests\Support\RecordsAndArchives\ArchiveFixture;

require_once dirname(__DIR__, 2) . '/Support/Integration/configuration-cli.php';

/**
 * Freeze exact acquired revisions through the public CLI for a fresh destination.
 */
function filesystem_publication_run(ArchiveFixture $archive, array $descriptor, array $items, string $destination): string
{
    $broadcast = configuration_result(configuration_cli($archive, $descriptor, ['broadcast:create', 'filesystem', $archive->path($destination)]));
    $selection = $archive->path('selection.jsonl');
    file_put_contents($selection, implode('', array_map(static fn (array $item): string => json_encode($item, JSON_THROW_ON_ERROR) . "\n", $items)));

    return configuration_result(configuration_cli($archive, $descriptor, ['broadcast:select', $broadcast['id'], $selection]))['id'];
}

/**
 * Acquire a real discovery and retain its exact Item revisions rather than current heads.
 */
function filesystem_publication_acquire(ArchiveFixture $archive, array $descriptor, string $input): array
{
    $scan = configuration_result(configuration_cli($archive, $descriptor, ['input:discover', $input]));
    configuration_result(configuration_cli($archive, $descriptor, ['input:acquire', $scan['id']]));
    $attempts = configuration_result(configuration_cli($archive, $descriptor, ['input:acquisitions', $scan['id']]))['items'];

    return array_map(static function (array $attempt) use ($archive): array {
        $item = json_decode($attempt['result'], true, flags: JSON_THROW_ON_ERROR)['item'];
        $record = Tests\Support\RecordsAndArchives\TransactionProbe::json(Tests\Support\RecordsAndArchives\OcflInspector::payloadPath(
            $archive->path('vault'),
            $item['object_id'],
            $item['version'],
            'item.json',
        ));
        expect($record['pins'])->toHaveCount(1);
        foreach ($record['pins'] as $pin) {
            expect(is_file(Tests\Support\RecordsAndArchives\OcflInspector::payloadPath($archive->path('vault'), $pin['object_id'], $pin['version'], 'data.bin')))->toBeTrue();
        }

        $directory = $archive->path('vault/extensions/stashd-transactions/' . hash('sha256', $attempt['id']));
        expect(glob($directory . '/custody-[0-9]*'))->toBe([]);
        if ($attempt['status'] === 'preserved') {
            expect(is_file($directory . '/custody-release.json'))->toBeTrue();
        }

        return ['item_id' => $item['object_id'], 'version' => $item['version']];
    }, $attempts);
}

test('Filesystem reconstructs historical files after source removal using fresh CLI processes', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor): void {
        $files = [
            'binary.bin' => "\0\xff\x01",
            'notes/report.txt' => 'document',
            'media/clip.mp4' => random_bytes(4096),
            'nested/report.txt' => 'different basename',
            '日本語/spaced name.txt' => 'unicode',
            'empty' => '',
        ];
        $witnesses = [];
        foreach ($files as $path => $bytes) {
            $source = $archive->input($path, $bytes);
            $witnesses[$path] = ['size' => filesize($source), 'sha512' => hash_file('sha512', $source)];
        }

        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        configuration_result(configuration_cli($archive, $descriptor, ['vault:init']));
        $input = configuration_result(configuration_cli($archive, $descriptor, ['input:create', 'filesystem', $archive->path('inputs')]));
        $old = filesystem_publication_acquire($archive, $descriptor, $input['id']);
        file_put_contents($archive->path('inputs/binary.bin'), 'new historical bytes');
        $new = filesystem_publication_acquire($archive, $descriptor, $input['id']);
        $oldRun = filesystem_publication_run($archive, $descriptor, $old, 'old-output');
        $newRun = filesystem_publication_run($archive, $descriptor, $new, 'new-output');
        $archive->destroy('inputs');
        expect(is_dir($archive->path('inputs')))->toBeFalse();
        configuration_result(configuration_cli($archive, $descriptor, ['broadcast:build', $oldRun]));
        configuration_result(configuration_cli($archive, $descriptor, ['broadcast:build', $newRun]));
        foreach ($witnesses as $path => $witness) {
            expect(filesize($archive->path('old-output/' . $path)))->toBe($witness['size']);
            expect(hash_file('sha512', $archive->path('old-output/' . $path)))->toBe($witness['sha512']);
        }

        expect(file_get_contents($archive->path('new-output/binary.bin')))->toBe('new historical bytes');
        $inode = fileinode($archive->path('old-output/binary.bin'));
        configuration_result(configuration_cli($archive, $descriptor, ['broadcast:build', $oldRun]));
        expect(fileinode($archive->path('old-output/binary.bin')))->toBe($inode);
        $inspection = configuration_result(configuration_cli($archive, $descriptor, ['broadcast:publication', $oldRun]));
        expect($inspection['run']['status'])->toBe('complete')->and($inspection['results'])->toHaveCount(count($files));
        file_put_contents($archive->path('old-output/binary.bin'), 'consumer changed export');
        $isolated = filesystem_publication_run($archive, $descriptor, $old, 'isolated-output');
        configuration_result(configuration_cli($archive, $descriptor, ['broadcast:build', $isolated]));
        expect(hash_file('sha512', $archive->path('isolated-output/binary.bin')))->toBe($witnesses['binary.bin']['sha512']);
    });
});

test('Filesystem rejects unsafe destinations and invalid provenance before copying', function (string $fault): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database) use ($fault): void {
        $archive->input('folder/file.txt', 'preserved');
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        configuration_result(configuration_cli($archive, $descriptor, ['vault:init']));
        $input = configuration_result(configuration_cli($archive, $descriptor, ['input:create', 'filesystem', $archive->path('inputs')]));
        $items = filesystem_publication_acquire($archive, $descriptor, $input['id']);
        $run = filesystem_publication_run($archive, $descriptor, $items, 'output');
        mkdir($archive->path('output'));
        if ($fault === 'symlink') {
            mkdir($archive->path('outside'));
            symlink($archive->path('outside'), $archive->path('output/folder'));
        } elseif ($fault === 'directory') {
            file_put_contents($archive->path('output/folder'), 'retain');
        } else {
            $query = $database->prepare('SELECT record FROM configuration_proof.broadcast_selection WHERE run_id = ?');
            $query->execute([$run]);
            $record = json_decode($query->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
            if ($fault === 'missing') {
                $record['metadata'] = [];
            } else {
                $source = json_decode($record['metadata'][0]['json'], true, flags: JSON_THROW_ON_ERROR);
                $source['path'] = '../escape';
                $record['metadata'][0]['json'] = json_encode($source, JSON_THROW_ON_ERROR);
            }

            $query = $database->prepare('UPDATE configuration_proof.broadcast_selection SET record = ? WHERE run_id = ?');
            $query->execute([json_encode($record, JSON_THROW_ON_ERROR), $run]);
        }

        $archive->destroy('inputs');
        expect(configuration_cli($archive, $descriptor, ['broadcast:build', $run])['code'])->not->toBe(0);
        $inspection = configuration_result(configuration_cli($archive, $descriptor, ['broadcast:publication', $run]));
        expect($inspection['run']['status'])->toBe('failed')->and($inspection['results'])->toBe([]);
        expect(file_exists($archive->path('outside/file.txt')))->toBeFalse();
        expect(glob($archive->path('output/.stashd-publication-*')))->toBe([]);
    });
})->with(['symlink', 'directory', 'missing', 'traversal']);

test('Filesystem preflights duplicate case-folded and structural selection collisions', function (string $path): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database) use ($path): void {
        $archive->input('file.txt', 'one');
        $archive->input('other.txt', 'two');
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        configuration_result(configuration_cli($archive, $descriptor, ['vault:init']));
        $input = configuration_result(configuration_cli($archive, $descriptor, ['input:create', 'filesystem', $archive->path('inputs')]));
        $items = filesystem_publication_acquire($archive, $descriptor, $input['id']);
        $run = filesystem_publication_run($archive, $descriptor, $items, 'output');
        $query = $database->prepare('SELECT position, record FROM configuration_proof.broadcast_selection WHERE run_id = ? ORDER BY position');
        $query->execute([$run]);
        $records = $query->fetchAll(PDO::FETCH_ASSOC);
        foreach ($records as $position => $row) {
            $record = json_decode($row['record'], true, flags: JSON_THROW_ON_ERROR);
            $source = json_decode($record['metadata'][0]['json'], true, flags: JSON_THROW_ON_ERROR);
            $source['path'] = $position === 0 ? 'file.txt' : $path;
            $record['metadata'][0]['json'] = json_encode($source, JSON_THROW_ON_ERROR);
            $update = $database->prepare('UPDATE configuration_proof.broadcast_selection SET record = ? WHERE run_id = ? AND position = ?');
            $update->execute([json_encode($record, JSON_THROW_ON_ERROR), $run, $row['position']]);
        }

        expect(configuration_cli($archive, $descriptor, ['broadcast:build', $run])['code'])->not->toBe(0);
        expect(file_exists($archive->path('output/file.txt')))->toBeFalse();
        $inspection = configuration_result(configuration_cli($archive, $descriptor, ['broadcast:publication', $run]));
        expect($inspection['run']['status'])->toBe('failed')->and($inspection['results'])->toBe([]);
    });
})->with(['file.txt', 'FILE.txt', 'file.txt/child']);

test('Filesystem refuses missing or corrupt selected Vault payloads without a final file', function (bool $missing): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor) use ($missing): void {
        $archive->input('file.bin', 'preserved');
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        configuration_result(configuration_cli($archive, $descriptor, ['vault:init']));
        $input = configuration_result(configuration_cli($archive, $descriptor, ['input:create', 'filesystem', $archive->path('inputs')]));
        $items = filesystem_publication_acquire($archive, $descriptor, $input['id']);
        $run = filesystem_publication_run($archive, $descriptor, $items, 'output');
        $payloads = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($archive->path('vault'), FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry->getFilename() !== 'data.bin') {
                continue;
            }

            $payloads[] = $entry->getPathname();
        }

        expect($payloads)->toHaveCount(1);
        if ($missing) {
            unlink($payloads[0]);
        } else {
            file_put_contents($payloads[0], 'corrupted');
        }

        $archive->destroy('inputs');
        expect(configuration_cli($archive, $descriptor, ['broadcast:build', $run])['code'])->not->toBe(0);
        expect(file_exists($archive->path('output/file.bin')))->toBeFalse();
        $inspection = configuration_result(configuration_cli($archive, $descriptor, ['broadcast:publication', $run]));
        expect($inspection['run']['status'])->toBe('failed')->and($inspection['results'])->toBe([]);
        expect(glob($archive->path('output/.stashd-publication-*')))->toBe([]);
    });
})->with([false, true]);

test('Filesystem interruption during staging never exposes a partial final filename', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor): void {
        $source = $archive->input('large.bin', '');
        $writer = fopen($source, 'wb');
        if ($writer === false) {
            throw new RuntimeException('Could not create large publication fixture.');
        }

        for ($index = 0; $index < 128; $index++) {
            fwrite($writer, str_repeat('preserved bytes!', 65_536));
        }

        fclose($writer);
        $size = filesize($source);
        $digest = hash_file('sha512', $source);
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        configuration_result(configuration_cli($archive, $descriptor, ['vault:init']));
        $input = configuration_result(configuration_cli($archive, $descriptor, ['input:create', 'filesystem', $archive->path('inputs')]));
        $items = filesystem_publication_acquire($archive, $descriptor, $input['id']);
        $run = filesystem_publication_run($archive, $descriptor, $items, 'output');
        $archive->destroy('inputs');
        $child = start_configuration_cli($archive, $descriptor, ['broadcast:build', $run]);
        $observed = false;
        $deadline = microtime(true) + 20;
        do {
            foreach (glob($archive->path('output/.stashd-publication-*/bytes')) as $temporary) {
                clearstatcache(true, $temporary);
                $length = filesize($temporary);
                if ($length > 0 && $length < $size) {
                    proc_terminate($child['process'], 19);
                    clearstatcache(true, $temporary);
                    $length = filesize($temporary);
                    $observed = $length > 0 && $length < $size;
                    break 2;
                }
            }

            usleep(1_000);
        } while (microtime(true) < $deadline);

        proc_terminate($child['process'], 9);
        $result = finish_configuration_cli($child);
        expect($observed)->toBeTrue();
        expect($result['code'])->not->toBe(0);
        expect(file_exists($archive->path('output/large.bin')))->toBeFalse();
        $inspection = configuration_result(configuration_cli($archive, $descriptor, ['broadcast:publication', $run]));
        expect($inspection['results'])->toBe([]);
        configuration_result(configuration_cli($archive, $descriptor, ['broadcast:build', $run]));
        expect(filesize($archive->path('output/large.bin')))->toBe($size);
        expect(hash_file('sha512', $archive->path('output/large.bin')))->toBe($digest);
    });
});

test('Filesystem retries after real process death between rename and report acknowledgement', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $archive->input('file.bin', random_bytes(65_536));
        $digest = hash_file('sha512', $archive->path('inputs/file.bin'));
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        configuration_result(configuration_cli($archive, $descriptor, ['vault:init']));
        $input = configuration_result(configuration_cli($archive, $descriptor, ['input:create', 'filesystem', $archive->path('inputs')]));
        $items = filesystem_publication_acquire($archive, $descriptor, $input['id']);
        $run = filesystem_publication_run($archive, $descriptor, $items, 'output');
        $archive->destroy('inputs');
        $database->exec(<<<'SQL'
        CREATE FUNCTION configuration_proof.pause_report() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN PERFORM pg_advisory_xact_lock(7861, 75); RETURN NEW; END $$;
        CREATE TRIGGER pause_report BEFORE INSERT ON configuration_proof.broadcast_reports
        FOR EACH ROW EXECUTE FUNCTION configuration_proof.pause_report();
        SQL);
        $database->query('SELECT pg_advisory_lock(7861, 75)');
        $child = start_configuration_cli($archive, $descriptor, ['broadcast:build', $run]);
        try {
            $deadline = microtime(true) + 15;
            do {
                $waiting = (int) $database
                    ->query(
                        "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND NOT granted AND database = (SELECT oid FROM pg_database WHERE datname = current_database())",
                    )
                    ->fetchColumn();
                if ($waiting > 0) {
                    break;
                }

                usleep(20_000);
            } while (microtime(true) < $deadline);

            expect($waiting)->toBe(1);
            expect(hash_file('sha512', $archive->path('output/file.bin')))->toBe($digest);
            $inode = fileinode($archive->path('output/file.bin'));
            proc_terminate($child['process'], 9);
            $result = finish_configuration_cli($child);
            expect($result['code'])->not->toBe(0);
            $inspection = configuration_result(configuration_cli($archive, $descriptor, ['broadcast:publication', $run]));
            expect($inspection['run']['status'])->toBe('running')->and($inspection['results'])->toBe([]);
        } finally {
            $database->query('SELECT pg_advisory_unlock(7861, 75)');
            $database->exec('DROP TRIGGER pause_report ON configuration_proof.broadcast_reports');
        }

        configuration_result(configuration_cli($archive, $descriptor, ['broadcast:build', $run]));
        expect(fileinode($archive->path('output/file.bin')))->toBe($inode);
        expect(hash_file('sha512', $archive->path('output/file.bin')))->toBe($digest);
        $inspection = configuration_result(configuration_cli($archive, $descriptor, ['broadcast:publication', $run]));
        expect($inspection['run']['status'])->toBe('complete')->and($inspection['results'])->toHaveCount(1);
    });
});

test('Filesystem refuses differing bytes and retries the same selection without pruning', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor): void {
        $archive->input('file.txt', 'preserved');
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        configuration_result(configuration_cli($archive, $descriptor, ['vault:init']));
        $input = configuration_result(configuration_cli($archive, $descriptor, ['input:create', 'filesystem', $archive->path('inputs')]));
        $items = filesystem_publication_acquire($archive, $descriptor, $input['id']);
        $run = filesystem_publication_run($archive, $descriptor, $items, 'output');
        mkdir($archive->path('output'));
        file_put_contents($archive->path('output/file.txt'), 'different');
        file_put_contents($archive->path('output/unrelated'), 'retain');
        $archive->destroy('inputs');
        expect(configuration_cli($archive, $descriptor, ['broadcast:build', $run])['code'])->not->toBe(0);
        expect(file_get_contents($archive->path('output/file.txt')))->toBe('different');
        $inspection = configuration_result(configuration_cli($archive, $descriptor, ['broadcast:publication', $run]));
        expect($inspection['run']['status'])->toBe('failed')->and($inspection['results'])->toBe([]);
        file_put_contents($archive->path('output/file.txt'), 'preserved');
        $inode = fileinode($archive->path('output/file.txt'));
        configuration_result(configuration_cli($archive, $descriptor, ['broadcast:build', $run]));
        expect(fileinode($archive->path('output/file.txt')))->toBe($inode);
        expect(file_get_contents($archive->path('output/unrelated')))->toBe('retain');
        expect(glob($archive->path('output/.stashd-publication-*')))->toBe([]);
    });
});
