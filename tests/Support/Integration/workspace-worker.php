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
 * Observe real workspace verification or fail initialization inside an isolated fixture.
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
     * Delegate normal signing, or inject a controlled setup failure before a receipt is opened.
     * Record only filesystem observations; never manufacture a successful application result.
     */
    public function sign(string $data): Signature
    {
        $fault = getenv('PR4_INITIALIZATION_FAILURE');
        if ($fault === false) {
            return $this->signer->sign($data);
        }

        $claim = json_decode($data, true, flags: JSON_THROW_ON_ERROR);
        $root = $claim[2];
        $workspace = $root . '/' . $claim[5];
        $receipt = '';
        if ($fault === 'nonempty') {
            file_put_contents($workspace . '/.operator-owned', 'retain these bytes');
        } elseif ($fault === 'replacement' || $fault === 'symlink') {
            rename($workspace, dirname($root) . '/original-workspace');
            if ($fault === 'replacement') {
                mkdir($workspace, 0o700);
            } else {
                symlink(dirname($root) . '/outside', $workspace);
            }
        } elseif ($fault === 'receipt collision') {
            $signature = $this->signer->sign($data);
            $receipt = $workspace . '.' . $claim[6] . '.' . $claim[7] . '.' . $signature->value . '.owner';
            file_put_contents($receipt, 'retain this conflicting receipt');
        }

        file_put_contents(dirname($root) . '/initialization-failure.json', json_encode([
            'fault' => $fault,
            'workspace' => $workspace,
            'device' => $claim[6],
            'inode' => $claim[7],
            'receipt' => $receipt,
        ], JSON_THROW_ON_ERROR));
        if ($fault === 'receipt collision') {
            return $signature;
        }

        throw new RuntimeException('Controlled workspace signing failure.');
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
