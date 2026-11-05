<?php

declare(strict_types=1);

use Tests\Support\Integration\Pr1RoundTrip;
use Tests\Support\Integration\Pr2Directory;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

require_once dirname(__DIR__, 2) . '/Support/Integration/configuration-cli.php';

test('competing admission exhausts all pages without limiting outstanding Assignments', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        $corpus = Pr2Directory::corpus($archive, 137);
        $scope = Pr2Directory::discover($archive, $descriptor, $corpus['source'], 137);
        $first = start_configuration_cli($archive, $descriptor, ['input:acquire', $scope['run']]);
        $second = start_configuration_cli($archive, $descriptor, ['input:acquire', $scope['run']]);
        configuration_result(finish_configuration_cli($first));
        configuration_result(finish_configuration_cli($second));
        $state = Pr2Directory::admission($archive, $descriptor, $scope['run'], 137);
        expect($state['admitted'])->toBe(137);
        expect($state['outstanding'])->toBe(137);
        expect($state['settled'])->toBe(0);
        Pr2Directory::assertPerFileAssignments($database, $scope['run'], 137);
        Pr2Directory::assertCursorCovered($database, $scope['run'], $state['cursor']);

        // Terminal-failure control tests retry pagination independently of physical preservation.
        $reject = $database->prepare(
            "UPDATE configuration_proof.operation_assignments SET active = false, error = 'terminal failure control' WHERE uuid IN (SELECT assignment_id FROM configuration_proof.acquisition_attempts WHERE run_id = ?)",
        );
        $reject->execute([$scope['run']]);
        expect(Pr2Directory::admission($archive, $descriptor, $scope['run'], 137)['failed'])->toBe(137);
        Pr1RoundTrip::command($archive, $descriptor, ['input:acquire', $scope['run']]);
        $retry = Pr2Directory::admission($archive, $descriptor, $scope['run'], 137);
        expect($retry['failed'])->toBe(0);
        expect($retry['outstanding'])->toBe(137);
        Pr2Directory::assertPerFileAssignments($database, $scope['run'], 137, allowRetries: true);
        Pr2Directory::assertCursorCovered($database, $scope['run'], $retry['cursor']);
    });
});

test('independent sources share Input protection but scans and the same source remain excluded', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        Pr1RoundTrip::command($archive, $descriptor, ['integration:init']);
        $database->query("SELECT pg_advisory_lock_shared(hashtext('configuration_proof'), hashtext('input-control'))");
        $database->query("SELECT pg_advisory_lock(hashtext('configuration_proof'), hashtext('acquire:input-control:source-one'))");
        try {
            $probe = static function (string $operation, string $source) use ($archive, $descriptor): bool {
                $child = start_configuration_cli($archive, $descriptor, [$operation, 'input-control', $source], bootstrap: 'tests/Support/Integration/acquisition-lock-probe.php');

                return configuration_result(finish_configuration_cli($child))['acquired'];
            };
            expect($probe('acquire', 'source-one'))->toBeFalse();
            expect($probe('acquire', 'source-two'))->toBeTrue();
            expect($probe('scan', 'source-two'))->toBeFalse();
        } finally {
            $database->query('SELECT pg_advisory_unlock_all()');
        }
    });
});
