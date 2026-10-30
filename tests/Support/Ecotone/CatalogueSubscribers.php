<?php

declare(strict_types=1);

namespace Tests\Support\Ecotone;

use App\EventManagement\Events\Integrity\FixityCheckPassed;
use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use RuntimeException;

/**
 * Exercise independent durable deliveries of one existing catalogue event.
 *
 * These disposable evidence tables are not a Vault PREMIS implementation.
 */
final readonly class CatalogueSubscribers
{
    /**
     * Write only to the disposable fixture and optionally fail client delivery.
     */
    public function __construct(
        private Connection $database,
        private bool $failClient = false,
    ) {}

    /**
     * Retain the catalogue identity in independently delivered operational history.
     */
    #[Asynchronous('history')]
    #[EventHandler(endpointId: 'evaluation.history')]
    public function history(FixityCheckPassed $event): void
    {
        $this->record('history', $event, ['checkId' => $event->checkId]);
    }

    /**
     * Record the PREMIS classification without asserting an archival commit.
     */
    #[Asynchronous('premis')]
    #[EventHandler(endpointId: 'evaluation.premis')]
    public function premis(FixityCheckPassed $event): void
    {
        $this->record('premis', $event, ['assetId' => $event->assetId, 'assetVersion' => $event->assetVersion, 'verifiedDigest' => $event->verifiedDigest]);
    }

    /**
     * Project a fixed safe field list rather than serializing all event metadata.
     */
    #[Asynchronous('client')]
    #[EventHandler(endpointId: 'evaluation.client')]
    public function client(FixityCheckPassed $event): void
    {
        if ($this->failClient) {
            throw new RuntimeException('Injected client subscriber failure.');
        }

        $this->record('client', $event, ['itemId' => $event->itemId, 'checkId' => $event->checkId]);
    }

    /**
     * Preserve stable event IDs while allowing safe replay of this projection.
     *
     * @param array<string, string> $fields
     */
    private function record(string $kind, FixityCheckPassed $event, array $fields): void
    {
        $this->database->executeStatement('INSERT INTO event_probe (kind, id, fields) VALUES (?, ?, ?) ON CONFLICT DO NOTHING', [
            $kind,
            $event->metadata->eventId,
            json_encode($fields, JSON_THROW_ON_ERROR),
        ]);
    }
}
