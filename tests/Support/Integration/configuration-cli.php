<?php

use Tests\Support\RecordsAndArchives\ArchiveFixture;
use Tests\Support\RecordsAndArchives\ProjectionProbe;
use Tests\Support\RecordsAndArchives\TransactionProbe;

/**
 * Run a configuration proof against an explicitly provisioned disposable database.
 *
 * The existing fixture creates an unprivileged owner and always removes its own
 * database/role. Production commands receive only that owner's connection settings.
 */
function with_configuration_database(Closure $proof): void
{
    $archive = new ArchiveFixture();
    $workspace = dirname($archive->path('vault'));
    $name = 'stashd_tx_test_' . bin2hex(random_bytes(8));
    $fixture = ['database' => $name, 'username' => $name, 'schema' => $name];
    $created = false;
    try {
        $descriptor = TransactionProbe::run($workspace, ['operation' => 'projection.describe', 'fixture' => $fixture]);
        $created = true;
        $database = ProjectionProbe::connect($descriptor, $name);
        try {
            $proof($archive, $descriptor, $database);
        } finally {
            $database = null;
        }
    } finally {
        if ($created) {
            TransactionProbe::run($workspace, ['operation' => 'projection.dispose', 'fixture' => $fixture]);
        }
        $archive->dispose();
    }
}

/**
 * Start a fresh Tempest process with isolated operational and Vault configuration.
 *
 * No internal services are mocked. Failure output is returned for explicit status
 * assertions; credentials are passed only through the child environment.
 */
function configuration_cli(ArchiveFixture $archive, array $descriptor, array $arguments, array $overrides = []): array
{
    return finish_configuration_cli(start_configuration_cli($archive, $descriptor, $arguments, $overrides));
}

/**
 * Start the real command asynchronously so concurrent configuration can be observed.
 *
 * The caller must finish the child and close both output pipes.
 */
function start_configuration_cli(ArchiveFixture $archive, array $descriptor, array $arguments, array $overrides = []): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, 'tempest', ...$arguments, '--no-interaction'],
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
            ...$overrides,
        ],
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start configuration CLI.');
    }
    return ['process' => $process, 'pipes' => $pipes];
}

/**
 * Collect a started command's exit status and close its process-owned pipes.
 */
function finish_configuration_cli(array $child): array
{
    $output = stream_get_contents($child['pipes'][1]);
    $errors = stream_get_contents($child['pipes'][2]);
    fclose($child['pipes'][1]);
    fclose($child['pipes'][2]);
    return ['code' => proc_close($child['process']), 'output' => $output, 'errors' => $errors];
}

/**
 * Require a successful CLI invocation and decode its public JSON response.
 */
function configuration_result(array $result): array
{
    expect($result['code'])->toBe(0, $result['output'] . $result['errors']);
    return json_decode(trim($result['output']), true, flags: JSON_THROW_ON_ERROR);
}
