<?php

use Tests\Support\RecordsAndArchives\ArchiveFixture;
use Tests\Support\RecordsAndArchives\TransactionProbe;

require_once dirname(__DIR__, 2) . '/Support/Integration/configuration-cli.php';

test('configuration survives fresh CLI processes without changing source destination Vault or projection', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $sourceFile = $archive->input('document.bin', "preserve me\0\xff");
        $source = dirname($sourceFile);
        $destination = $archive->path('output/<em>資料 with spaces');
        $vaultBefore = TransactionProbe::snapshot($archive->path('vault'));
        // Witness an unrelated projection-like table to catch destructive bootstrap.
        $database->exec("CREATE TABLE configuration_witness (value text); INSERT INTO configuration_witness VALUES ('retained')");
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        $input = configuration_result(configuration_cli($archive, $descriptor, ['input:create', 'filesystem', $source . '/']));
        $broadcast = configuration_result(configuration_cli($archive, $descriptor, ['broadcast:create', 'filesystem', $destination]));
        expect($input)
            ->toMatchArray(['kind' => 'input', 'component' => 'filesystem', 'settings' => ['root' => $source]])
            ->and($broadcast)
            ->toMatchArray(['kind' => 'broadcast', 'component' => 'filesystem', 'settings' => ['root' => $destination]]);
        expect($input['id'])->not->toBe($broadcast['id']);
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        foreach ([
            ['input',     $input],
            ['broadcast', $broadcast],
        ] as [$kind, $record]) {
            expect(configuration_result(configuration_cli($archive, $descriptor, [$kind . ':inspect', $record['id']])))
                ->toBe($record)
                ->and(configuration_result(configuration_cli($archive, $descriptor, [$kind . ':create', 'filesystem', $record['settings']['root']])))
                ->toBe($record);
        }
        $rows = $database->query('SELECT id::text, kind, component, settings FROM configuration_proof.configurations ORDER BY kind')->fetchAll(PDO::FETCH_ASSOC);
        expect(array_column($rows, 'id'))
            ->toBe([$broadcast['id'], $input['id']])
            ->and($database->query('SELECT value FROM configuration_witness')->fetchColumn())
            ->toBe('retained')
            ->and(file_get_contents($sourceFile))
            ->toBe("preserve me\0\xff")
            ->and(file_exists($destination))
            ->toBeFalse()
            ->and(TransactionProbe::snapshot($archive->path('vault')))
            ->toBe($vaultBefore);
        $archive->destroy('inputs');
        expect(configuration_result(configuration_cli($archive, $descriptor, ['input:inspect', $input['id']])))->toBe($input);
        expect(configuration_cli($archive, $descriptor, ['broadcast:inspect', $input['id']])['code'])->not->toBe(0);
    });
});

test('real configuration commands reject unsafe roots and unsupported components without persisting them', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $source = dirname($archive->input('file', 'untouched'));
        mkdir($source . '/nested');
        mkdir($archive->path('inputs-sibling'));
        file_put_contents($archive->path('vault/sentinel'), 'canonical witness');
        symlink($source, $archive->path('linked'));
        symlink($archive->path('absent'), $archive->path('dangling'));
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        configuration_result(configuration_cli($archive, $descriptor, ['input:create', 'filesystem', $source]));
        $badCommands = [
            ['input:create', 'filesystem', $archive->path('missing')],
            ['input:create', 'filesystem', $source . '/file'],
            ['input:create', 'filesystem', 'relative/path'],
            ['input:create', 'filesystem', $source . '/nested/..'],
            ['input:create', 'filesystem', $source . '//nested'],
            ['input:create', 'filesystem', $archive->path('linked')],
            ['broadcast:create', 'filesystem', $archive->path('linked') . '/new'],
            ['broadcast:create', 'filesystem', $archive->path('dangling') . '/new'],
            ['broadcast:create', 'filesystem', $source],
            ['broadcast:create', 'filesystem', $source . '/nested/new'],
            ['broadcast:create', 'filesystem', dirname($source)],
            ['broadcast:create', 'filesystem', $archive->path('vault')],
            ['broadcast:create', 'filesystem', $archive->path('vault/new')],
            ['broadcast:create', 'filesystem', '/'],
            ['input:create', 'external-runtime', $archive->path('inputs-sibling')],
        ];
        foreach ($badCommands as $command) {
            $failure = configuration_cli($archive, $descriptor, $command);
            expect($failure['code'])->not->toBe(0, implode(' ', $command));
            expect((int) $database->query('SELECT count(*) FROM configuration_proof.configurations')->fetchColumn())->toBe(1);
        }
        // A common string prefix is not directory ancestry.
        configuration_result(configuration_cli($archive, $descriptor, ['broadcast:create', 'filesystem', $archive->path('inputs-sibling')]));
        expect(file_get_contents($source . '/file'))
            ->toBe('untouched')
            ->and(file_get_contents($archive->path('vault/sentinel')))
            ->toBe('canonical witness')
            ->and(file_exists($source . '/nested/new'))
            ->toBeFalse();
        $failure = configuration_cli($archive, $descriptor, ['broadcast:create', 'filesystem', $archive->path('elsewhere')], ['VAULT_ROOT' => $source]);
        expect($failure['code'])
            ->not
            ->toBe(0)
            ->and((int) $database->query('SELECT count(*) FROM configuration_proof.configurations')->fetchColumn())
            ->toBe(2);
    });
});

test('operational setup refuses implicit connections and unsafe schema names', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        foreach ([
            ['STASHD_OPERATIONS_DSN' => ''],
            ['STASHD_OPERATIONS_DSN' => 'sqlite::memory:'],
            ['STASHD_OPERATIONS_USERNAME' => ''],
            ['STASHD_OPERATIONS_SCHEMA' => 'public'],
            ['STASHD_OPERATIONS_SCHEMA' => 'bad; DROP SCHEMA public'],
        ] as $override) {
            expect(configuration_cli($archive, $descriptor, ['integration:init'], $override)['code'])->not->toBe(0);
        }
        expect($database->query("SELECT to_regclass('configuration_proof.configurations')")->fetchColumn())->toBeNull();
    });
});

test('concurrent CLI configuration waits for authority and retains one Input identity', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $source = dirname($archive->input('file', 'original'));
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        $database->beginTransaction();
        $database->query("SELECT pg_advisory_xact_lock(hashtext(current_database()), hashtext('configuration_proof'))");
        $children = [];
        try {
            $children[] = start_configuration_cli($archive, $descriptor, ['input:create', 'filesystem', $source]);
            $children[] = start_configuration_cli($archive, $descriptor, ['input:create', 'filesystem', $source]);
            $deadline = microtime(true) + 10;
            do {
                $waiting = (int) $database->query("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND NOT granted
                    AND database = (SELECT oid FROM pg_database WHERE datname = current_database())")->fetchColumn();
                if ($waiting === 2) {
                    break;
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            expect($waiting)->toBe(2);
        } finally {
            $database->rollBack();
            $results = array_map(finish_configuration_cli(...), $children);
        }
        $first = configuration_result($results[0]);
        expect(configuration_result($results[1]))
            ->toBe($first)
            ->and((int) $database->query('SELECT count(*) FROM configuration_proof.configurations')->fetchColumn())
            ->toBe(1);
    });
});
