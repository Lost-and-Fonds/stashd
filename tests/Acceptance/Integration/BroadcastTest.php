<?php

use Tests\Support\RecordsAndArchives\ArchiveFixture;

require_once dirname(__DIR__, 2) . '/Support/Integration/configuration-cli.php';

/**
 * Execute the public Broadcast service in a fresh application process with fixture credentials.
 */
function broadcast_probe(ArchiveFixture $archive, array $descriptor, string $run, string $mode = 'success'): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, 'tests/Support/Integration/broadcast-probe.php', $run, $mode],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__, 3),
        [
            ...getenv(),
            'ENVIRONMENT' => 'testing',
            'VAULT_ROOT' => $archive->path('vault'),
            'STASHD_OPERATIONS_DSN' => $descriptor['dsn'],
            'STASHD_OPERATIONS_USERNAME' => $descriptor['username'],
            'STASHD_OPERATIONS_PASSWORD' => $descriptor['password'],
            'STASHD_OPERATIONS_SCHEMA' => 'configuration_proof',
        ],
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start Broadcast service proof.');
    }

    return finish_configuration_cli(['process' => $process, 'pipes' => $pipes]);
}

test('Broadcast freezes exact revisions and grants only invocation-scoped verified Vault reads', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $source = $archive->input('file.bin', "old\0bytes");
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        configuration_cli($archive, $descriptor, ['vault:init']);
        $input = configuration_result(configuration_cli($archive, $descriptor, ['input:create', 'filesystem', dirname($source)]));
        $discovery = configuration_result(configuration_cli($archive, $descriptor, ['input:discover', $input['id']]));
        configuration_result(configuration_cli($archive, $descriptor, ['input:acquire', $discovery['id']]));
        $attempt = configuration_result(configuration_cli($archive, $descriptor, ['input:acquisitions', $discovery['id']]))['items'][0];
        $item = json_decode($attempt['result'], true, flags: JSON_THROW_ON_ERROR)['item']['object_id'];
        $broadcast = configuration_result(configuration_cli($archive, $descriptor, ['broadcast:create', 'filesystem', $archive->path('output')]));
        $selection = $archive->path('selection.jsonl');
        file_put_contents($selection, json_encode(['item_id' => $item, 'version' => 'v1']) . "\n");
        $run = configuration_result(configuration_cli($archive, $descriptor, ['broadcast:select', $broadcast['id'], $selection]))['id'];
        file_put_contents($source, 'new bytes');
        $discovery = configuration_result(configuration_cli($archive, $descriptor, ['input:discover', $input['id']]));
        configuration_result(configuration_cli($archive, $descriptor, ['input:acquire', $discovery['id']]));
        $archive->destroy('inputs');
        $result = configuration_result(broadcast_probe($archive, $descriptor, $run, 'duplicate'));
        expect($result['bytes'])->toBe([base64_encode("old\0bytes")]);
        expect($result['run']['status'])->toBe('complete');
        $inspection = configuration_result(configuration_cli($archive, $descriptor, ['broadcast:publication', $run]));
        expect($inspection['results'])->toHaveCount(2);
        expect(array_column($inspection['results'], 'kind'))->toBe(['file', 'metadata']);
        expect(configuration_result(broadcast_probe($archive, $descriptor, $run))['bytes'])->toBe([]);
        file_put_contents($selection, json_encode(['item_id' => $item, 'version' => 'v99']) . "\n");
        expect(configuration_cli($archive, $descriptor, ['broadcast:select', $broadcast['id'], $selection])['code'])->not->toBe(0);
        expect((int) $database->query('SELECT count(*) FROM configuration_proof.broadcast_runs')->fetchColumn())->toBe(1);
    });
});

test('Broadcast discards failed reports and retries the frozen selection with fresh grants', function (string $mode): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database) use ($mode): void {
        $source = $archive->input('file.bin', 'preserved');
        configuration_cli($archive, $descriptor, ['vault:init']);
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        $input = configuration_result(configuration_cli($archive, $descriptor, ['input:create', 'filesystem', dirname($source)]));
        $discovery = configuration_result(configuration_cli($archive, $descriptor, ['input:discover', $input['id']]));
        configuration_result(configuration_cli($archive, $descriptor, ['input:acquire', $discovery['id']]));
        $attempt = configuration_result(configuration_cli($archive, $descriptor, ['input:acquisitions', $discovery['id']]))['items'][0];
        $item = json_decode($attempt['result'], true, flags: JSON_THROW_ON_ERROR)['item']['object_id'];
        $broadcast = configuration_result(configuration_cli($archive, $descriptor, ['broadcast:create', 'filesystem', $archive->path('output')]));
        $selection = $archive->path('selection.jsonl');
        file_put_contents($selection, json_encode(['item_id' => $item, 'version' => 'v1']) . "\n");
        $run = configuration_result(configuration_cli($archive, $descriptor, ['broadcast:select', $broadcast['id'], $selection]))['id'];
        expect(broadcast_probe($archive, $descriptor, $run, $mode)['code'])->not->toBe(0);
        $inspection = configuration_result(configuration_cli($archive, $descriptor, ['broadcast:publication', $run]));
        expect($inspection['run']['status'])->toBe('failed')->and($inspection['results'])->toBe([]);
        expect((int) $database->query('SELECT count(*) FROM configuration_proof.broadcast_reports')->fetchColumn())->toBe(0);
        $result = configuration_result(broadcast_probe($archive, $descriptor, $run));
        expect($result['run']['status'])
            ->toBe('complete')
            ->and($result['bytes'])
            ->toBe([base64_encode('preserved')]);
    });
})->with(['failure', 'no-eof', 'not-applicable']);

test('empty selections permit complete output with bounded committed report pages', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        $broadcast = configuration_result(configuration_cli($archive, $descriptor, ['broadcast:create', 'filesystem', $archive->path('output')]));
        $selection = $archive->path('empty.jsonl');
        file_put_contents($selection, '');
        $run = configuration_result(configuration_cli($archive, $descriptor, ['broadcast:select', $broadcast['id'], $selection]))['id'];
        configuration_result(broadcast_probe($archive, $descriptor, $run, 'many-reports'));
        $positions = [];
        $after = -1;
        do {
            $page = configuration_result(configuration_cli($archive, $descriptor, ['broadcast:publication', $run, ...($after < 0 ? [] : [(string) $after])]))['results'];
            expect(count($page))->toBeLessThanOrEqual(100);
            foreach ($page as $row) {
                $after = $row['position'];
                $positions[] = $after;
            }
        } while ($page !== []);

        expect($positions)->toBe(range(0, 200));
        expect((int) $database->query('SELECT count(*) FROM configuration_proof.broadcast_reports')->fetchColumn())->toBe(201);
    });
});
