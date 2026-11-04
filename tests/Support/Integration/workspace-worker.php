<?php

declare(strict_types=1);

use Tempest\Console\ConsoleApplication;
use Tempest\Container\GenericContainer;
use Tempest\Cryptography\Signing\Signature;
use Tempest\Cryptography\Signing\Signer;
use Tempest\Cryptography\Signing\SigningAlgorithm;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$application = ConsoleApplication::boot();
$container = GenericContainer::instance();
$dsn = getenv('STASHD_OPERATIONS_DSN');
if (! is_string($dsn) || ! preg_match('/^pgsql:host=lerd-postgres;port=5432;dbname=stashd_tx_test_[a-f0-9]{16}$/D', $dsn)) {
    throw new RuntimeException('The workspace observer requires a disposable fixture database.');
}

$database = new PDO($dsn, getenv('STASHD_OPERATIONS_USERNAME'), getenv('STASHD_OPERATIONS_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$container->singleton(Signer::class, new /**
 * Observe real workspace verification without changing signatures or application outcomes.
 */ class($container->get(Signer::class), $database) implements Signer {
    public SigningAlgorithm $algorithm {
        get => $this->signer->algorithm;
    }

    /**
     * Delegate all cryptography; the independent database session only pauses the worker.
     */
    public function __construct(
        private Signer $signer,
        private PDO $database,
    ) {}

    /**
     * Preserve the application's real ownership signatures without substituting evidence.
     */
    public function sign(string $data): Signature
    {
        return $this->signer->sign($data);
    }

    /**
     * Pause after actual verification at the selected filesystem boundary.
     * The parent must observe the PostgreSQL wait and SIGKILL or release this child.
     */
    public function verify(string $data, Signature $signature): bool
    {
        $valid = $this->signer->verify($data, $signature);
        $workspace = getenv('PR4_WORKSPACE');
        $before = (int) getenv('PR4_ENTRY_COUNT');
        if ($valid && is_string($workspace)) {
            $present = is_dir($workspace);
            $remaining = $present ? count(glob($workspace . '/*')) : 0;
            if ($before === -1 && ! $present || $remaining > 0 && ($before === 0 || $before > 0 && $remaining < $before)) {
                $this->database->query('SELECT pg_advisory_lock(7195, 1)');
                $this->database->query('SELECT pg_advisory_unlock(7195, 1)');
            }
        }

        return $valid;
    }
});
$application->run();
