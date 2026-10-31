<?php

declare(strict_types=1);

use App\Operations\AttemptAuthority;
use App\Operations\ExecutionArtifacts;
use App\Operations\Jobs;
use App\Operations\Work;
use App\Operations\WorkExecutor;
use Tests\Support\OperationsFencing\FencingExecutor;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

try {
    $r = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $d = $r['descriptor'];
    foreach (['DSN' => $d['dsn'], 'USERNAME' => $d['username'], 'PASSWORD' => $d['password'], 'SCHEMA' => $d['schema']] as $key => $value) {
        putenv('STASHD_OPERATIONS_' . $key . '=' . $value);
        $_ENV['STASHD_OPERATIONS_' . $key] = $value;
    }

    putenv('STASHD_EXECUTION_LEASE_MS=4000');
    $_ENV['STASHD_EXECUTION_LEASE_MS'] = '4000';
    if ($r['action'] === 'observe') {
        $observer = \Tests\Support\Operations\LocalOperationsDriver::connect($d);
        $observer->beginTransaction();
        $statement = $observer->prepare('SELECT id FROM jobs WHERE id = ? FOR UPDATE');
        $statement->execute([$r['job_id']]);
        touch($r['prefix'] . '.observing');
        $until = microtime(true) + 15;
        while (! is_file($r['prefix'] . '.observer_release')) {
            if (microtime(true) > $until) {
                throw new RuntimeException('Independent snapshot barrier timed out.');
            }

            usleep(10_000);
        }

        $observer->commit();
        echo '{}';
        exit(0);
    }

    putenv('VAULT_ROOT=' . dirname($r['prefix']) . '/vault');
    $_ENV['VAULT_ROOT'] = dirname($r['prefix']) . '/vault';
    $c = \Tempest\Core\Tempest::boot(root: dirname(__DIR__, 3));
    $c->singleton(
        WorkExecutor::class,
        new FencingExecutor($r['prefix'], $c->get(AttemptAuthority::class), $c->get(ExecutionArtifacts::class), $c->get(\App\Operations\LeaseHeartbeat::class)),
    );
    if ($r['action'] === 'replay') {
        $original = $r['context'];
        $context = new \App\Operations\DTO\ExecutionContext(
            $original['jobId'],
            $original['attemptId'],
            $original['generation'],
            new DateTimeImmutable($original['leaseExpiresAt']['date']),
        );
        $host = $c->get(ExecutionArtifacts::class);
        try {
            $value = $r['command']['value'] ?? '';
            if ($r['command']['action'] === 'custody') {
                file_put_contents($r['prefix'] . '.custody', $value);
            }

            $result = match ($r['command']['action']) {
                'result' => $host->result($context, $value),
                'custody' => $host->custody($context, $value, $r['prefix'] . '.custody'),
                'checkpoint' => $host->checkpoint($context, $value),
                'reconcile' => $host->reconcile($context),
                'renew' => $c->get(AttemptAuthority::class)->renew($context),
                default => throw new RuntimeException('Unsupported settled-worker replay.'),
            };
            echo json_encode(['accepted' => $result !== false], JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            echo json_encode(['accepted' => false, 'reason' => $error->getMessage()], JSON_THROW_ON_ERROR);
        }

        exit(0);
    }

    if ($r['action'] === 'prepare') {
        echo json_encode(['job_id' => $c->get(Jobs::class)->request(new Work('fencing.probe', $r['name'], 'run', 'bounded-host'))], JSON_THROW_ON_ERROR);
    } else {
        $runtime = $c->get(\App\Operations\JobRuntime::class);
        $c->get(\Ecotone\Api\Messaging\ConfiguredMessagingSystem::class)->run(
            'jobs',
            \Ecotone\Api\ExtensionObject\ExecutionPollingMetadata::createWithDefaults()
                ->withStopOnError(false)
                ->withHandledMessageLimit(1)
                ->withExecutionTimeLimitInMilliseconds(1500),
        );
        file_put_contents($r['prefix'] . '.decision', $runtime->claimDecidedAt?->format('Y-m-d H:i:s.uP') ?? '');
        touch($r['prefix'] . '.settled');
        echo '{}';
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage());
    exit(1);
}
