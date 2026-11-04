<?php

use PHPUnit\Framework\Assert;
use Tests\Support\RecordsAndArchives\ArchiveFixture;
use Tests\Support\RecordsAndArchives\ProjectionProbe;
use Tests\Support\RecordsAndArchives\TransactionProbe;

/**
 * Run a CLI test against a new disposable database with an unprivileged owner.
 *
 * Give application commands only that owner's credentials. The fixture removes
 * its own database and role afterward, including when the test fails. Cleanup
 * errors propagate; onCleaned runs only after both database and files are removed.
 */
function with_configuration_database(Closure $proof, ?Closure $onCleaned = null): void
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
            $archive->assertOwned();
            TransactionProbe::run($workspace, ['operation' => 'projection.dispose', 'fixture' => $fixture]);
        }

        $archive->dispose();
        $onCleaned?->__invoke();
    }
}

/**
 * Run a fresh Tempest process with the test's database and Vault settings.
 *
 * Use real application services and return failure output for assertions. Pass
 * credentials through the child environment rather than command-line arguments.
 */
function configuration_cli(ArchiveFixture $archive, array $descriptor, array $arguments, array $overrides = []): array
{
    return finish_configuration_cli(start_configuration_cli($archive, $descriptor, $arguments, $overrides));
}

/**
 * Start a CLI command without waiting, so tests can check concurrent callers.
 *
 * The caller must finish the child and close both output pipes.
 */
function start_configuration_cli(ArchiveFixture $archive, array $descriptor, array $arguments, array $overrides = [], ?string $bootstrap = null): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $bootstrap ?? (str_starts_with($arguments[0], 'job:') ? 'tests/Support/HistoricalOperations/console.php' : 'tempest'), ...$arguments, '--no-interaction'],
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

    return ['process' => $process, 'pipes' => $pipes, 'tick' => $archive->onProcessTick, 'worker' => $arguments[0] === 'operations:work'];
}

/**
 * Read a command's output and exit status, then close its pipes and process.
 * Observed stress children drain both streams while ticking and are killed/reaped
 * on callback failure or timeout; other acceptance callers retain their existing path.
 */
function finish_configuration_cli(array $child): array
{
    if (($child['tick'] ?? null) instanceof Closure) {
        $output = '';
        $errors = '';
        foreach ($child['pipes'] as $pipe) {
            stream_set_blocking($pipe, false);
        }

        try {
            $deadline = microtime(true) + 3_600;
            do {
                $output .= stream_get_contents($child['pipes'][1]);
                $errors .= stream_get_contents($child['pipes'][2]);
                $status = proc_get_status($child['process']);
                $child['tick']($child['worker'] && $status['running'] ? 1 : 0);
                if (! $status['running']) {
                    break;
                }

                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Stress command exceeded its one-hour process deadline.');
                }

                usleep(250_000);
            } while (true);

            $output .= stream_get_contents($child['pipes'][1]);
            $errors .= stream_get_contents($child['pipes'][2]);
        } finally {
            if (proc_get_status($child['process'])['running']) {
                proc_terminate($child['process'], 9);
            }

            foreach ($child['pipes'] as $pipe) {
                fclose($pipe);
            }

            $closed = proc_close($child['process']);
        }

        return ['code' => $status['exitcode'] >= 0 ? $status['exitcode'] : $closed, 'output' => $output, 'errors' => $errors];
    }

    $output = stream_get_contents($child['pipes'][1]);
    $errors = stream_get_contents($child['pipes'][2]);
    fclose($child['pipes'][1]);
    fclose($child['pipes'][2]);

    return ['code' => proc_close($child['process']), 'output' => $output, 'errors' => $errors];
}

/**
 * Check that the command succeeded and decode the JSON it printed.
 */
function configuration_result(array $result): array
{
    Assert::assertSame(0, $result['code'], $result['output'] . $result['errors']);
    Assert::assertJson(trim($result['output']), $result['output'] . $result['errors']);

    return json_decode(trim($result['output']), true, flags: JSON_THROW_ON_ERROR);
}
