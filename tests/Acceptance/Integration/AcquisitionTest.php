<?php

use Tests\Support\RecordsAndArchives\ArchiveFixture;
use Tests\Support\RecordsAndArchives\OcflInspector;
use Tests\Support\RecordsAndArchives\TransactionProbe;

require_once dirname(__DIR__, 2) . '/Support/Integration/configuration-cli.php';

test('CLI acquisition preserves bytes, skips equal rescans and recovers identities after SQL association loss', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $root = dirname($archive->input('document.bin', "first\0bytes"));
        $archive->input('empty', '');
        configuration_cli($archive, $descriptor, ['vault:init']);
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        $input = configuration_result(configuration_cli($archive, $descriptor, ['input:create', 'filesystem', $root]));
        $run = configuration_result(configuration_cli($archive, $descriptor, ['input:discover', $input['id']]));
        $result = configuration_result(configuration_cli($archive, $descriptor, ['input:acquire', $run['id']]));
        expect($result['counts'])->toBe(['preserved' => 2]);
        $attempts = configuration_result(configuration_cli($archive, $descriptor, ['input:acquisitions', $run['id']]))['items'];
        foreach ($attempts as $attempt) {
            $result = json_decode($attempt['result'], true, flags: JSON_THROW_ON_ERROR);
            $record = TransactionProbe::json(OcflInspector::payloadPath($archive->path('vault'), $result['item']['object_id'], 'v1', 'item.json'));
            $pin = $record['pins'][0];
            $bytes = file_get_contents(OcflInspector::payloadPath($archive->path('vault'), $pin['object_id'], $pin['version'], 'data.bin'));
            expect($bytes)->toBe($attempt['source_id'] === hash('sha256', 'empty') ? '' : "first\0bytes");
            expect($record['uri'])->toBe($attempt['item_uri']);
            $directory = $archive->path('vault/extensions/stashd-transactions/' . hash('sha256', $attempt['id']));
            expect(glob($directory . '/custody-[0-9]*'))->toBe([]);
            expect(is_file($directory . '/custody-release.json'))->toBeTrue();
        }

        $before = TransactionProbe::snapshot($archive->path('vault'));
        $database->exec("UPDATE configuration_proof.acquisition_attempts SET status = 'acquiring', result = NULL");
        configuration_result(configuration_cli($archive, $descriptor, ['input:acquire', $run['id']]));
        expect(TransactionProbe::snapshot($archive->path('vault')))->toBe($before);
        $next = configuration_result(configuration_cli($archive, $descriptor, ['input:discover', $input['id']]));
        expect(configuration_result(configuration_cli($archive, $descriptor, ['input:acquire', $next['id']]))['counts'])->toBe(['unchanged' => 2]);
        expect(TransactionProbe::snapshot($archive->path('vault')))->toBe($before);
        $database->exec('DELETE FROM configuration_proof.acquisition_attempts; DELETE FROM configuration_proof.acquisition_sources');
        $next = configuration_result(configuration_cli($archive, $descriptor, ['input:discover', $input['id']]));
        expect(configuration_result(configuration_cli($archive, $descriptor, ['input:acquire', $next['id']]))['counts'])->toBe(['unchanged' => 2]);
        expect(TransactionProbe::snapshot($archive->path('vault')))->toBe($before);
        $restored = $database->query('SELECT item_uri FROM configuration_proof.acquisition_sources ORDER BY item_uri')->fetchAll(PDO::FETCH_COLUMN);
        $uris = array_column($attempts, 'item_uri');
        sort($uris);
        expect($restored)->toBe($uris);
        file_put_contents($root . '/document.bin', "other\0bytes");
        $next = configuration_result(configuration_cli($archive, $descriptor, ['input:discover', $input['id']]));
        $counts = configuration_result(configuration_cli($archive, $descriptor, ['input:acquire', $next['id']]))['counts'];
        expect($counts['preserved'])->toBe(1)->and($counts['unchanged'])->toBe(1);
        $archive->destroy('inputs');
        expect(configuration_result(configuration_cli($archive, $descriptor, ['input:acquire', $next['id']]))['counts'])->toBe($counts);
        foreach ($attempts as $attempt) {
            $result = json_decode($attempt['result'], true, flags: JSON_THROW_ON_ERROR);
            $record = TransactionProbe::json(OcflInspector::payloadPath($archive->path('vault'), $result['item']['object_id'], 'v1', 'item.json'));
            $pin = $record['pins'][0];
            expect(file_get_contents(OcflInspector::payloadPath($archive->path('vault'), $pin['object_id'], $pin['version'], 'data.bin')))
                ->toBe($attempt['source_id'] === hash('sha256', 'empty') ? '' : "first\0bytes");
        }
    });
});

test('acquisition refuses a replaced symlink and retries the same attempt after the source is restored', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $path = $archive->input('safe.bin', 'safe');
        configuration_cli($archive, $descriptor, ['vault:init']);
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        $input = configuration_result(configuration_cli($archive, $descriptor, ['input:create', 'filesystem', dirname($path)]));
        $run = configuration_result(configuration_cli($archive, $descriptor, ['input:discover', $input['id']]));
        unlink($path);
        file_put_contents($archive->path('outside'), 'outside');
        symlink($archive->path('outside'), $path);
        expect(configuration_cli($archive, $descriptor, ['input:acquire', $run['id']])['code'])->not->toBe(0);
        $attempt = configuration_result(configuration_cli($archive, $descriptor, ['input:acquisitions', $run['id']]))['items'][0];
        expect($attempt['status'])->toBe('retryable_failure');
        unlink($path);
        file_put_contents($path, 'safe');
        expect(configuration_result(configuration_cli($archive, $descriptor, ['input:acquire', $run['id']]))['counts'])->toBe(['preserved' => 1]);
        $retried = configuration_result(configuration_cli($archive, $descriptor, ['input:acquisitions', $run['id']]))['items'][0];
        expect($retried['id'])->toBe($attempt['id'])->and($retried['item_uri'])->toBe($attempt['item_uri']);
    });
});

test('a sealed persisted attempt resumes through CLI after its acquisition source disappears', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $path = $archive->input('sealed.bin', 'sealed bytes');
        configuration_cli($archive, $descriptor, ['vault:init']);
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        $input = configuration_result(configuration_cli($archive, $descriptor, ['input:create', 'filesystem', dirname($path)]));
        $run = configuration_result(configuration_cli($archive, $descriptor, ['input:discover', $input['id']]));
        unlink($path);
        expect(configuration_cli($archive, $descriptor, ['input:acquire', $run['id']])['code'])->not->toBe(0);
        $attempt = configuration_result(configuration_cli($archive, $descriptor, ['input:acquisitions', $run['id']]))['items'][0];
        file_put_contents($path, 'sealed bytes');
        $workspace = dirname($archive->path('vault'));
        $request = [
            'operation' => 'prepare',
            'transaction_id' => $attempt['id'],
            'uri' => $attempt['item_uri'],
            'changes' => ['original' => $path],
            'metadata' => ['stashd.filesystem.source' => ['version' => 1, 'input_id' => $input['id'], 'path' => 'sealed.bin', 'root' => dirname($path), 'outcome' => 'complete']],
            'preconditions' => [],
        ];
        expect(TransactionProbe::run($workspace, $request)['status'])->toBe('sealed');
        $archive->destroy('inputs');
        expect(configuration_result(configuration_cli($archive, $descriptor, ['input:acquire', $run['id']]))['counts'])->toBe(['preserved' => 1]);
        $before = TransactionProbe::snapshot($archive->path('vault'));
        expect(configuration_result(configuration_cli($archive, $descriptor, ['input:acquire', $run['id']]))['counts'])->toBe(['preserved' => 1]);
        expect(TransactionProbe::snapshot($archive->path('vault')))->toBe($before);
    });
});

test('killed acquisition after Vault commit retries without source bytes or duplicate revisions', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $path = $archive->input('crash.bin', 'preserved before SQL acknowledgement');
        configuration_cli($archive, $descriptor, ['vault:init']);
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        $input = configuration_result(configuration_cli($archive, $descriptor, ['input:create', 'filesystem', dirname($path)]));
        $run = configuration_result(configuration_cli($archive, $descriptor, ['input:discover', $input['id']]));
        $database->exec(<<<'SQL'
        CREATE FUNCTION configuration_proof.pause_ack() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN IF NEW.status = 'preserved' THEN PERFORM pg_advisory_xact_lock(7863, 28); END IF; RETURN NEW; END $$;
        CREATE TRIGGER pause_ack BEFORE UPDATE ON configuration_proof.acquisition_attempts
        FOR EACH ROW EXECUTE FUNCTION configuration_proof.pause_ack();
        SQL);
        $database->query('SELECT pg_advisory_lock(7863, 28)');
        $child = start_configuration_cli($archive, $descriptor, ['input:acquire', $run['id']]);
        try {
            $deadline = microtime(true) + 10;
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
            $before = TransactionProbe::snapshot($archive->path('vault'));
            $archive->destroy('inputs');
        } finally {
            proc_terminate($child['process'], 9);
            $deadline = microtime(true) + 5;
            do {
                $status = proc_get_status($child['process']);
                if ($status['running']) {
                    usleep(10_000);
                }
            } while ($status['running'] && microtime(true) < $deadline);

            $database->query('SELECT pg_advisory_unlock(7863, 28)');
            finish_configuration_cli($child);
        }

        expect($status['signaled'])->toBeTrue()->and($status['termsig'])->toBe(9);
        $database->query('SELECT id FROM configuration_proof.acquisition_attempts FOR UPDATE')->fetchAll();
        expect(configuration_result(configuration_cli($archive, $descriptor, ['input:acquire', $run['id']]))['counts'])->toBe(['preserved' => 1]);
        expect(TransactionProbe::snapshot($archive->path('vault')))->toBe($before);
    });
});

test('healthy acquisition and reassociation isolate unrelated damaged Items and journals without advancing sealed work', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $path = $archive->input('healthy.bin', 'healthy');
        configuration_cli($archive, $descriptor, ['vault:init']);
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        $input = configuration_result(configuration_cli($archive, $descriptor, ['input:create', 'filesystem', dirname($path)]));
        $workspace = dirname($archive->path('vault'));
        $request = [
            'operation' => 'execute',
            'transaction_id' => 'unrelated-damaged',
            'uri' => 'urn:example:unrelated',
            'changes' => ['original' => $path],
            'metadata' => ['unrelated' => true],
            'preconditions' => [],
        ];
        $completed = TransactionProbe::run($workspace, $request);
        $itemPath = OcflInspector::payloadPath($archive->path('vault'), $completed['item']['object_id'], 'v1', 'item.json');
        file_put_contents($itemPath, 'damaged unrelated Item');
        $journal = $archive->path('vault/extensions/stashd-transactions/' . hash('sha256', 'unrelated-damaged'));
        file_put_contents($journal . '/complete.json', '{damaged');
        $sealed = [...$request, 'operation' => 'prepare', 'transaction_id' => 'unrelated-sealed', 'uri' => 'urn:example:unrelated-sealed'];
        expect(TransactionProbe::run($workspace, $sealed)['status'])->toBe('sealed');
        $sealedDirectory = $archive->path('vault/extensions/stashd-transactions/' . hash('sha256', 'unrelated-sealed'));
        $beforeJournal = TransactionProbe::snapshot($journal);
        $beforeSealed = TransactionProbe::snapshot($sealedDirectory);
        $run = configuration_result(configuration_cli($archive, $descriptor, ['input:discover', $input['id']]));
        expect(configuration_result(configuration_cli($archive, $descriptor, ['input:acquire', $run['id']]))['counts'])->toBe(['preserved' => 1]);
        $database->exec('DELETE FROM configuration_proof.acquisition_attempts; DELETE FROM configuration_proof.acquisition_sources');
        $next = configuration_result(configuration_cli($archive, $descriptor, ['input:discover', $input['id']]));
        expect(configuration_result(configuration_cli($archive, $descriptor, ['input:acquire', $next['id']]))['counts'])->toBe(['unchanged' => 1]);
        expect(TransactionProbe::snapshot($journal))->toBe($beforeJournal);
        expect(TransactionProbe::snapshot($sealedDirectory))->toBe($beforeSealed);
        expect(file_get_contents($itemPath))->toBe('damaged unrelated Item');
    });
});

test('relevant damaged journals cannot mint replacement identities after association loss', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $path = $archive->input('relevant.bin', 'preserved');
        configuration_cli($archive, $descriptor, ['vault:init']);
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        $input = configuration_result(configuration_cli($archive, $descriptor, ['input:create', 'filesystem', dirname($path)]));
        $run = configuration_result(configuration_cli($archive, $descriptor, ['input:discover', $input['id']]));
        configuration_result(configuration_cli($archive, $descriptor, ['input:acquire', $run['id']]));
        $attempt = configuration_result(configuration_cli($archive, $descriptor, ['input:acquisitions', $run['id']]))['items'][0];
        $journal = $archive->path('vault/extensions/stashd-transactions/' . hash('sha256', $attempt['id']));
        file_put_contents($journal . '/complete.json', '{damaged');
        $database->exec('DELETE FROM configuration_proof.acquisition_attempts; DELETE FROM configuration_proof.acquisition_sources');
        $next = configuration_result(configuration_cli($archive, $descriptor, ['input:discover', $input['id']]));
        $before = TransactionProbe::snapshot($archive->path('vault'));
        expect(configuration_cli($archive, $descriptor, ['input:acquire', $next['id']])['code'])->not->toBe(0);
        expect((int) $database->query('SELECT count(*) FROM configuration_proof.acquisition_sources')->fetchColumn())->toBe(0);
        expect(TransactionProbe::snapshot($archive->path('vault')))->toBe($before);
    });
});
