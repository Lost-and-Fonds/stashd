<?php

declare(strict_types=1);

use App\Integration\OperationsConfig;
use App\Operations\JobRuntime;
use App\Operations\Jobs;
use App\Operations\Work;
use Symfony\Component\Messenger\Exception\TransportException;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

require_once dirname(__DIR__, 2) . '/Support/Integration/configuration-cli.php';

test('Work admission and promotion roll back when their outbox write fails', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        $runtime = new JobRuntime(new OperationsConfig($descriptor['dsn'], $descriptor['username'], $descriptor['password'], 'configuration_proof'));
        $jobs = new Jobs($runtime);
        $work = new Work('discovery', 'scope-1', 'refresh', 'revision-1');
        $database->exec("ALTER TABLE configuration_proof.messenger_messages ADD CONSTRAINT reject_work_outbox CHECK (queue_name <> 'outbox')");
        expect(fn () => $jobs->request($work))->toThrow(TransportException::class);
        expect((int) $database->query('SELECT count(*) FROM configuration_proof.jobs')->fetchColumn())->toBe(0);
        $database->exec('ALTER TABLE configuration_proof.messenger_messages DROP CONSTRAINT reject_work_outbox');
        $id = $jobs->request($work);
        $database->exec("ALTER TABLE configuration_proof.messenger_messages ADD CONSTRAINT reject_promoted_outbox CHECK (queue_name <> 'outbox') NOT VALID");
        expect(fn () => $jobs->request($work, 'interactive'))->toThrow(TransportException::class);
        $row = $database->query('SELECT id, desired_priority, generation FROM configuration_proof.jobs')->fetch(PDO::FETCH_ASSOC);
        expect($row['id'])
            ->toBe($id)
            ->and($row['desired_priority'])
            ->toBe('routine')
            ->and((int) $row['generation'])
            ->toBe(0);
        expect((int) $database->query('SELECT count(*) FROM configuration_proof.messenger_messages')->fetchColumn())->toBe(1);
    });
});

test('running Work coalesces a new observation and priority without restarting its Attempt', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        $runtime = new JobRuntime(new OperationsConfig($descriptor['dsn'], $descriptor['username'], $descriptor['password'], 'configuration_proof'));
        $jobs = new Jobs($runtime);
        $id = $jobs->request(new Work('discovery', 'scope-1', 'refresh', 'revision-1'));
        $database->exec("UPDATE configuration_proof.jobs SET status = 'running', claim_token = 'existing-worker', lease_until = clock_timestamp() + interval '60 seconds'");
        expect($jobs->request(new Work('discovery', 'scope-1', 'refresh', 'revision-2'), 'interactive'))->toBe($id);
        $row = $database->query('SELECT payload, desired_priority, generation, claim_token FROM configuration_proof.jobs')->fetch(PDO::FETCH_ASSOC);
        expect($row['payload'])
            ->toBe('revision-1')
            ->and($row['desired_priority'])
            ->toBe('interactive')
            ->and((int) $row['generation'])
            ->toBe(0)
            ->and($row['claim_token'])
            ->toBe('existing-worker');
        expect((int) $database->query('SELECT count(*) FROM configuration_proof.messenger_messages')->fetchColumn())->toBe(1);
    });
});
