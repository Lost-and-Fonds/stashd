<?php

declare(strict_types=1);

namespace App\Integration;

use App\Integration\DTO\BroadcastSelection;
use App\RecordsAndArchives\AssetArchive;
use App\RecordsAndArchives\ItemArchive;
use Generator;
use InvalidArgumentException;
use League\Uri\Urn;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Freeze exact archival selections and commit reports only after successful publication.
 *
 * @phpstan-import-type FrozenItem from BroadcastStore
 */
final readonly class BroadcastCoordinator
{
    /**
     * Reuse configured destinations, operational persistence and verified archive readers.
     */
    public function __construct(
        private IntegrationConfigurations $configurations,
        private BroadcastStore $store,
        private ItemArchive $items,
        private AssetArchive $assets,
    ) {}

    /**
     * Validate and persist the complete selection before any publisher executes.
     *
     * @param iterable<BroadcastSelection> $selection
     */
    public function select(string $broadcast, iterable $selection): string
    {
        $configuration = $this->configurations->read('broadcast', $broadcast);
        $id = Uuid::v7()->toString();
        $this->store->freeze($id, $configuration, $this->records($selection));

        return $id;
    }

    /**
     * Run or retry one fixed selection; completed runs are returned without republishing.
     */
    public function execute(string $id, BroadcastPublisher $publisher): array
    {
        $run = $this->store->read($id);

        return $this->store->locked($run['broadcast_id'], function () use ($id, $publisher): array {
            $run = $this->store->read($id);
            if ($run['status'] === 'complete') {
                return $run;
            }

            $attempt = Uuid::v7()->toString();
            $this->store->begin($id, $attempt);
            $invocation = new BroadcastInvocation($this->store, $this->assets, $id, $attempt);
            try {
                $status = $publisher->publish($invocation, json_decode($run['settings'], true, flags: JSON_THROW_ON_ERROR));
                $invocation->finish($status);
                $invocation->close();
                $this->store->complete($id, $status->value);
            } catch (Throwable $error) {
                $this->store->fail($id, $error->getMessage());
                throw $error;
            } finally {
                $invocation->close();
            }

            return $this->store->read($id);
        });
    }

    /**
     * Resolve explicit Item revisions to their verified exact pins without reading heads.
     *
     * @param iterable<mixed> $selection
     * @return Generator<int, FrozenItem>
     */
    private function records(iterable $selection): Generator
    {
        foreach ($selection as $selected) {
            if (! $selected instanceof BroadcastSelection) {
                throw new InvalidArgumentException('Selections must be explicit Item revision values.');
            }

            $item = $this->items->read(Urn::fromString($selected->itemId), $selected->version);
            $assets = [];
            foreach ($item['pins'] as $pin) {
                $asset = $this->assets->inspect(Urn::fromString($pin['object_id']), $pin['version']);
                if ($asset['item_object_id'] !== $selected->itemId || count($asset['files']) !== 1) {
                    throw new InvalidArgumentException('This slice requires one byte representation per pinned Asset belonging to the selected Item.');
                }

                $path = array_key_first($asset['files']);
                $assets[] = ['id' => $pin['object_id'], 'version' => $pin['version'], 'path' => $path, 'size' => $asset['files'][$path]['size']];
            }

            $metadata = [];
            foreach ($item['metadata'] as $schema => $value) {
                $metadata[] = ['schema' => (string) $schema, 'json' => json_encode($value, JSON_THROW_ON_ERROR)];
            }

            yield ['id' => $selected->itemId, 'version' => $selected->version, 'assets' => $assets, 'metadata' => $metadata];
        }
    }
}
