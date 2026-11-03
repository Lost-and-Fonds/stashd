<?php

declare(strict_types=1);

namespace App\Integration;

use App\Catalogue\Item;
use App\Catalogue\ItemSource;
use App\Operations\Authority\PublicationAuthority;
use App\Operations\DTO\AssignmentContext;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Tempest\Database\Query;

use function Tempest\Database\query;

/**
 * Keep operational source associations and retry identities separate from the Vault.
 *
 * SQL records coordinate work but never prove preservation. Callers hold the
 * Input lock while creating attempts or restoring canonical source claims.
 */
final readonly class AcquisitionStore
{
    /**
     * Retain the lazy operational connection without opening it at application startup.
     */
    public function __construct(
        private OperationsDatabase $database,
        private PublicationAuthority $authority,
    ) {}

    /**
     * Save a source claim or reject a conflicting URI without replacing its identity.
     */
    public function associate(string $input, string $source, string $uri): void
    {
        $database = $this->database->connect();
        if (Item::select()->where('uri', $uri)->first() === null) {
            new Item($uri)->insert();
        }

        if (ItemSource::select()->where('input_id', $input)->where('source_id', $source)->first() === null) {
            new ItemSource($input, $source, $uri)->insert();
        }

        $saved = $database->fetchFirst(query('acquisition_sources')->select('item_uri')->where('input_id', $input)->where('source_id', $source));
        if (($saved['item_uri'] ?? null) !== $uri) {
            throw new RuntimeException('Operational source association contradicts canonical provenance.');
        }
    }

    /**
     * Install verified source claims together, rolling back every insertion on conflict.
     *
     * The caller holds the Input lock and validates the complete claim set first.
     * Worker calls supply their context so revocation rolls back identity restoration.
     *
     * @param array<string, string> $claims Source ID to canonical Item URI.
     */
    public function restore(string $input, array $claims, ?AssignmentContext $context = null): void
    {
        $work = function () use ($input, $claims): void {
            foreach ($claims as $source => $uri) {
                $this->associate($input, $source, $uri);
            }
        };
        if ($context === null) {
            $this->database->transaction($work);
        } else {
            $this->authority->protect($context, $work);
        }
    }

    /**
     * Save an Item URI and transaction UUID atomically before any bytes are staged.
     *
     * Retries return the existing attempt. The caller must hold the Input lock.
     * A worker context fences Item, source mapping and attempt creation together.
     */
    public function start(string $run, string $input, string $source, ?AssignmentContext $context = null): array
    {
        $work = function () use ($run, $input, $source): array {
            $database = $this->database->connect();
            $saved = $database->fetchFirst(query('acquisition_sources')->select('item_uri')->where('input_id', $input)->where('source_id', $source));
            $uri = $saved['item_uri'] ?? 'urn:uuid:' . Uuid::v7()->toString();
            $this->associate($input, $source, $uri);
            $attempt = $database->fetchFirst(
                query('acquisition_attempts')->select('id', 'run_id', 'source_id', 'item_uri', 'status', 'result', 'error')->where('run_id', $run)->where('source_id', $source),
            );
            if ($attempt !== null) {
                if ($attempt['item_uri'] !== $uri) {
                    throw new RuntimeException('Acquisition identity contradicts its source association.');
                }

                return $attempt;
            }

            $attempt = ['id' => Uuid::v7()->toString(), 'run_id' => $run, 'source_id' => $source, 'item_uri' => $uri, 'status' => 'acquiring', 'result' => null, 'error' => null];
            $database->execute(query('acquisition_attempts')->insert(...$attempt));

            return $attempt;
        };

        return $context === null ? $this->database->transaction($work) : $this->authority->protect($context, $work);
    }

    /**
     * Save the verified outcome after archival work, retaining the attempt's identity.
     *
     * A failed SQL acknowledgement is retried against the same Vault transaction.
     *
     * @param array $result Verified completion, unchanged evidence, or failure details.
     */
    public function save(string $id, string $status, array $result): void
    {
        $this->database
            ->connect()
            ->execute(query('acquisition_attempts')->update(status: $status, result: json_encode($result, JSON_THROW_ON_ERROR), error: null)->where('id', $id));
    }

    /**
     * Retain the error for retry without deleting the attempt or canonical evidence.
     */
    public function fail(string $id, string $error): void
    {
        $this->database->connect()->execute(query('acquisition_attempts')->update(status: 'retryable_failure', error: $error)->where('id', $id));
    }

    /**
     * Read at most 100 saved attempts after a source cursor without opening source files.
     */
    public function items(string $run, string $after = ''): array
    {
        return $this->database
            ->connect()
            ->fetch(
                query('acquisition_attempts')
                    ->select('id', 'run_id', 'source_id', 'item_uri', 'status', 'result', 'error')
                    ->where('run_id', $run)
                    ->whereRaw('source_id > ?', $after)
                    ->orderBy('source_id')
                    ->limit(100),
            );
    }

    /**
     * Find unfinished work belonging only to this Input before beginning another run.
     */
    public function pending(string $input): array
    {
        return $this->database
            ->connect()
            ->fetch(
                new Query("SELECT a.* FROM acquisition_attempts a JOIN discovery_runs r ON r.id = a.run_id WHERE r.input_id = ? AND a.status IN ('acquiring', 'retryable_failure') ORDER BY a.id", [
                    $input,
                ]),
            );
    }
}
