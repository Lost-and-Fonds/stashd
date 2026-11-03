<?php

declare(strict_types=1);

use Tests\Support\Integration\Pr1RoundTrip;
use Tests\Support\Integration\Pr2Directory;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

require_once dirname(__DIR__, 2) . '/Support/Integration/configuration-cli.php';

/**
 * A repeated refill names its historical representative explicitly, even after all file work settled.
 */
test('admission names the first source Assignment without claiming it was newly admitted', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $corpus = Pr2Directory::corpus($archive, 3);
        $scope = Pr2Directory::discover($archive, $descriptor, $corpus['source'], 3);
        $first = Pr1RoundTrip::command($archive, $descriptor, ['input:acquire', $scope['run']]);
        expect($first['first_assignment_id'] ?? null)->toBe($first['assignment_id']);
        Pr2Directory::acquire($archive, $descriptor, $scope['run'], 3);
        $demands = (int) $database->query('SELECT count(*) FROM configuration_proof.operation_demands')->fetchColumn();
        $again = Pr1RoundTrip::command($archive, $descriptor, ['input:acquire', $scope['run']]);
        expect($again['first_assignment_id'])
            ->toBe($first['first_assignment_id'])
            ->and($again['assignment_id'])
            ->toBe($again['first_assignment_id'])
            ->and($again['settled'])
            ->toBe(3)
            ->and($again['outstanding'])
            ->toBe(0)
            ->and($again['failed'])
            ->toBe(0);
        expect((int) $database->query('SELECT count(*) FROM configuration_proof.operation_demands')->fetchColumn())->toBe($demands);
    });
});

/**
 * A terminal workflow failure occupies an unresolved slot but must never be labelled healthy in-flight work.
 */
test('admission reports terminal Durable failure without reconciling or retrying it', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $corpus = Pr2Directory::corpus($archive, 1);
        $scope = Pr2Directory::discover($archive, $descriptor, $corpus['source'], 1);
        $receipt = Pr1RoundTrip::command($archive, $descriptor, ['input:acquire', $scope['run']]);
        $archive->destroy('inputs');
        $deadline = microtime(true) + 30;
        do {
            $work = configuration_cli($archive, $descriptor, ['operations:work', '--once']);
            expect($work['code'])->toBe(0, $work['output'] . $work['errors']);
            $status = Pr1RoundTrip::command($archive, $descriptor, ['operations:inspect', $receipt['assignment_id']]);
            if ($status['state'] === 'failed') {
                break;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        expect($status['state'])->toBe('failed');
        $before = $database->query('SELECT uuid, active, error FROM configuration_proof.operation_assignments ORDER BY uuid')->fetchAll(PDO::FETCH_ASSOC);
        $inspection = Pr2Directory::admission($archive, $descriptor, $scope['run'], 1);
        expect($inspection['status'])
            ->toBe('failed')
            ->and($inspection['failed'] ?? null)
            ->toBe(1)
            ->and($inspection['outstanding'])
            ->toBe(1)
            ->and($inspection['settled'])
            ->toBe(0);
        expect($database->query('SELECT uuid, active, error FROM configuration_proof.operation_assignments ORDER BY uuid')->fetchAll(PDO::FETCH_ASSOC))->toBe($before);
        expect($before[1]['active'])->toBeTrue()->and($before[1]['error'])->toBeNull('The failure must be observed before admission reconciles its SQL row.');
    });
});
