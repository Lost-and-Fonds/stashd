<?php

declare(strict_types=1);

use App\Operations\Durable\DurableRuntime;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\PostgreSqlStore;
use Tests\Support\Durable\ProbeWorkflow;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
$request = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$params = $request['params'];
if (! preg_match('/^stashd_tx_test_[a-f0-9]{16}$/D', $params['dbname'])) {
    throw new RuntimeException('Only disposable spike databases are allowed.');
}

$journal = DriverManager::getConnection($params);
$application = DriverManager::getConnection($params);
$lockConnection = DriverManager::getConnection($params);
$runtime = new DurableRuntime(
    $journal,
    $application,
    new LockFactory(
        $request['expiring'] ?? false
            ? new Symfony\Component\Lock\Store\PdoStore($lockConnection->getNativeConnection())
            : new PostgreSqlStore($lockConnection->getNativeConnection()),
    ),
);
$runtime->workflows->registerClass(ProbeWorkflow::class);
$runtime->activities->register('spike.effect', static function (array $payload) use ($application, $lockConnection, $request): string {
    if (isset($request['barrier'])) {
        file_put_contents($request['barrier'], (string) $lockConnection->fetchOne('SELECT pg_backend_pid()'));
        $deadline = microtime(true) + 10;
        while (! is_file($request['barrier'] . '.release')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Probe barrier timed out.');
            }

            usleep(10_000);
        }
    }

    $application->insert('effects', ['value' => $payload['value']]);

    return $payload['value'];
});
$runtime->drain($request['receiver'] ?? null);
