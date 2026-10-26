<?php

declare(strict_types=1);

namespace App\Integration;

use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Tempest\Database\Query;

use function Tempest\Database\query;

final readonly class AcquisitionStore
{
    public function __construct(
        private OperationsDatabase $database,
    ) {}

    public function associate(string $input, string $source, string $uri): void
    {
        $database = $this->database->connect();
        $database->execute(
            new Query('INSERT INTO acquisition_sources (input_id, source_id, item_uri) VALUES (?, ?, ?) ON CONFLICT (input_id, source_id) DO NOTHING', [$input, $source, $uri]),
        );
        $saved = $database->fetchFirst(query('acquisition_sources')->select('item_uri')->where('input_id', $input)->where('source_id', $source));
        if (($saved['item_uri'] ?? null) !== $uri) {
            throw new RuntimeException('Operational source association contradicts canonical provenance.');
        }
    }

    public function start(string $run, string $input, string $source): array
    {
        return $this->database->transaction(function () use ($run, $input, $source): array {
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
        });
    }

    public function save(string $id, string $status, array $result): void
    {
        $this->database
            ->connect()
            ->execute(query('acquisition_attempts')->update(status: $status, result: json_encode($result, JSON_THROW_ON_ERROR), error: null)->where('id', $id));
    }

    public function fail(string $id, string $error): void
    {
        $this->database->connect()->execute(query('acquisition_attempts')->update(status: 'retryable_failure', error: $error)->where('id', $id));
    }

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
