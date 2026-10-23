<?php

namespace App\Integration;

use App\Integration\DTO\DiscoveryRequest;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Start or resume an Input scan, validate its output, and ask the store to save it.
 *
 * An unfinished run keeps its original configuration and complete-scan intent.
 * Discovery records source locations; it does not create canonical Item URIs.
 */
final readonly class DiscoveryCoordinator
{
    private const int BATCH_SIZE = 100;

    /**
     * Connect Input settings, the adapter, batch validation and the discovery store.
     */
    public function __construct(
        private IntegrationConfigurations $configurations,
        private InputDiscovery $input,
        private DiscoveryBatchValidator $batches,
        private DiscoveryStore $store,
    ) {}

    /**
     * Start or resume a manual scan and return the status saved in the database.
     *
     * A failure keeps earlier saved batches for retry. Once a finished result is
     * saved, a later adapter error cannot undo it. Only one caller may scan this
     * Input at a time; the database releases its lock when the process exits.
     */
    public function discover(string $inputId): array
    {
        $configuration = $this->configurations->read('input', $inputId);
        $inputId = $configuration['id'];
        $adapter = match ($configuration['component']) {
            'filesystem' => $this->input,
            default => throw new InvalidArgumentException('Input has no registered in-process discovery adapter.'),
        };
        $this->store->lock($inputId);
        try {
            $run = $this->store->start($configuration);
            $id = $run['id'];
            $continuation = $run['continuation'];
            $active = true;
            try {
                $adapter->discover(new DiscoveryRequest(
                    input_id: $inputId,
                    intent: 'complete',
                    options: $configuration['settings'],
                    continuation: $continuation,
                    refresh_state: null,
                    maximum_items_per_batch: self::BATCH_SIZE,
                ), function (array $values) use ($id, &$continuation, &$active): void {
                    if (! $active) {
                        throw new RuntimeException('Discovery acknowledgment is outside its invocation.');
                    }

                    $batch = $this->batches->validate($values, self::BATCH_SIZE);
                    $this->store->commit($id, $continuation, $batch);
                    $continuation = $batch->progress['more'] ?? null;
                });
                $result = $this->store->read($id);
                if ($result['status'] !== 'finished') {
                    throw new RuntimeException('Discovery returned without an acknowledged terminal batch.');
                }

                return $result;
            } catch (Throwable $error) {
                $result = $this->store->read($id);
                if ($result['status'] === 'finished') {
                    return $result;
                }

                $this->store->fail($id, $error->getMessage());
                throw new RuntimeException('Discovery run ' . $id . ' remains resumable: ' . $error->getMessage(), previous: $error);
            } finally {
                $active = false;
            }
        } finally {
            $this->store->unlock($inputId);
        }
    }

    /**
     * Read saved scan status without opening the source directory.
     */
    public function read(string $runId): array
    {
        return $this->store->read($runId);
    }

    /**
     * Read at most one page of saved files.
     *
     * Pass the returned source ID to read the next page; it is not an Item URI.
     */
    public function items(string $runId, string $after = ''): array
    {
        return $this->store->items($runId, $after, self::BATCH_SIZE);
    }
}
