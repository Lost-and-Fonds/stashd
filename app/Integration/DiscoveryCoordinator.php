<?php

namespace App\Integration;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Run the acknowledged Input lifecycle independently of SQL storage mechanics.
 *
 * Runs fix configuration and complete-enumeration intent across invocations. Only
 * a validated batch reaches the store; discovery never mints canonical Item URIs.
 */
final readonly class DiscoveryCoordinator
{
    private const int BATCH_SIZE = 100;

    /**
     * Bind configuration, discovery, value validation and operational persistence.
     */
    public function __construct(
        private IntegrationConfigurations $configurations,
        private InputDiscovery $input,
        private DiscoveryBatchValidator $batches,
        private DiscoveryStore $store,
    ) {}

    /**
     * Start or resume manual discovery and return durable status.
     *
     * Earlier acknowledgments survive failures; terminal acknowledgment wins over
     * a later exception. The session lock is released normally or by process death.
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
     * Inspect durable coverage without contacting the source filesystem.
     */
    public function read(string $runId): array
    {
        return $this->store->read($runId);
    }

    /**
     * Return one bounded page; the opaque cursor is a source ID, never an Item URI.
     */
    public function items(string $runId, string $after = ''): array
    {
        return $this->store->items($runId, $after, self::BATCH_SIZE);
    }
}
