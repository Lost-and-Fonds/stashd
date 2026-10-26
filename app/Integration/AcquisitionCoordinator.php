<?php

declare(strict_types=1);

namespace App\Integration;

use App\Integration\DTO\DiscoveredItem;
use App\RecordsAndArchives\AssetArchive;
use App\RecordsAndArchives\ItemArchive;
use App\RecordsAndArchives\PreservationTransaction;
use App\RecordsAndArchives\Vault;
use League\Uri\Urn;
use RuntimeException;
use Throwable;

use function Tempest\Mapper\map;

/**
 * Preserve saved discoveries through recoverable Vault transactions, one file at a time.
 *
 * Source locations never become Item identity. Operational attempts retain their
 * transaction IDs across retries; only verified archival evidence proves success.
 */
final readonly class AcquisitionCoordinator
{
    /**
     * Bind acquisition, operational persistence and archive services without starting work.
     */
    public function __construct(
        private IntegrationConfigurations $configurations,
        private DiscoveryStore $discovery,
        private AcquisitionStore $store,
        private FilesystemAcquisition $filesystem,
        private PreservationTransaction $transactions,
        private ItemArchive $items,
        private AssetArchive $assets,
        private Vault $vault,
        private FilesystemRoots $roots,
    ) {}

    /**
     * Acquire a finished scan under its Input lock and report saved outcome counts.
     *
     * Earlier unfinished work is recovered first. Failures retain retry identities,
     * release the lock, and propagate rather than inventing preservation success.
     */
    public function acquire(string $runId): array
    {
        $run = $this->discovery->read($runId);
        if ($run['status'] !== 'finished') {
            throw new RuntimeException('Finish discovery before acquiring its saved files.');
        }

        $input = $this->configurations->read('input', $run['input_id']);
        if ($input['component'] !== 'filesystem') {
            throw new RuntimeException('No acquisition adapter is registered for this Input.');
        }

        $this->discovery->lock($input['id']);
        try {
            foreach ($this->store->pending($input['id']) as $pending) {
                assert(is_array($pending), 'The store returns acquisition attempt records.');
                if ($pending['run_id'] === $runId) {
                    continue;
                }

                $recovered = $this->transactions->commit($pending['id']);
                if ($recovered['status'] !== 'complete') {
                    throw new RuntimeException('Retry the earlier acquisition run before starting another run.');
                }

                $this->release($pending['id']);
                $this->store->save($pending['id'], 'preserved', $recovered);
            }

            $this->reassociate($input['id']);
            $after = '';
            $counts = [];
            do {
                $page = $this->discovery->items($runId, $after, 100);
                assert(is_array($page['items']), 'Discovery pages contain saved observation records.');
                foreach ($page['items'] as $observation) {
                    $item = map($observation)->to(DiscoveredItem::class);
                    assert($item instanceof DiscoveredItem, 'The mapper must return a discovered Item.');
                    $attempt = $this->store->start($runId, $input['id'], $item->id);
                    try {
                        $result = $this->one($input, $item, $attempt);
                        $status = $result['status'];
                        $this->store->save($attempt['id'], $status, $result);
                        $counts[$status] = ($counts[$status] ?? 0) + 1;
                    } catch (Throwable $error) {
                        $this->store->fail($attempt['id'], $error->getMessage());
                        throw $error;
                    }
                }

                $after = $page['next'];
            } while ($after !== null);

            return ['run_id' => $runId, 'counts' => $counts];
        } finally {
            $this->discovery->unlock($input['id']);
        }
    }

    /**
     * Inspect one bounded attempt page even when the original source is unavailable.
     */
    public function read(string $run, string $after = ''): array
    {
        $this->discovery->read($run);
        $rows = $this->store->items($run, $after);

        $last = end($rows);

        return ['items' => $rows, 'next' => count($rows) === 100 && is_array($last) ? $last['source_id'] : null];
    }

    /**
     * Replay an exact result or stage and preserve one observation with stable identity.
     *
     * Unchanged decisions verify exact Item pins and byte evidence. Normal exits
     * discard invocation staging; verified completion releases redundant custody.
     */
    private function one(array $input, DiscoveredItem $item, array $attempt): array
    {
        $recovered = $this->transactions->commit($attempt['id']);
        if ($recovered['status'] === 'complete') {
            $this->release($attempt['id']);

            return [...$recovered, 'status' => 'preserved'];
        }

        if ($recovered['status'] !== 'absent') {
            return $recovered;
        }

        if ($attempt['status'] === 'unchanged') {
            $saved = json_decode($attempt['result'], true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($saved) || ! is_array($saved['pin'] ?? null)) {
                throw new RuntimeException('Malformed saved unchanged result.');
            }

            $asset = $this->assets->inspect(Urn::fromString($saved['pin']['object_id']), $saved['pin']['version']);
            if ($asset['item_uri'] !== $attempt['item_uri']) {
                throw new RuntimeException('Saved unchanged result belongs to another Item.');
            }

            if (! is_array($saved['item'] ?? null)) {
                throw new RuntimeException('Saved unchanged result has no canonical Item revision.');
            }

            $record = $this->items->read(Urn::fromString($saved['item']['object_id']), $saved['item']['version']);
            if ($record['uri'] !== $attempt['item_uri'] || ! in_array(['object_id' => $saved['pin']['object_id'], 'version' => $saved['pin']['version']], $record['pins'], true)) {
                throw new RuntimeException('Saved unchanged result is not pinned by its canonical Item revision.');
            }

            return $saved;
        }

        $staging = new AcquisitionStaging();
        try {
            $receipt = $this->filesystem->acquire($input['id'], $input['settings'], $item, $staging);
            $path = $staging->path($receipt);
            $metadata = ['stashd.filesystem.source' => [
                'version' => 1,
                'input_id' => $input['id'],
                'path' => $receipt->relative_path,
                'root' => $receipt->root,
                'outcome' => $receipt->outcome,
            ]];
            $current = $this->current($attempt['item_uri']);
            if ($current !== null && ($current['metadata']['stashd.filesystem.source'] ?? null) === $metadata['stashd.filesystem.source']) {
                $assetId = $this->assets->objectId($attempt['item_uri'], 'original')->toString();
                assert(is_array($current['pins']), 'Verified Item records contain exact Asset pins.');
                foreach ($current['pins'] as $pin) {
                    assert(is_array($pin), 'Verified pins are named Object and version pairs.');
                    if ($pin['object_id'] !== $assetId) {
                        continue;
                    }

                    $asset = $this->assets->inspect(Urn::fromString($assetId), $pin['version']);
                    if (($asset['files']['data.bin']['size'] ?? null) === $receipt->size_bytes && ($asset['files']['data.bin']['digest'] ?? null) === $receipt->digest) {
                        return [
                            'status' => 'unchanged',
                            'item_uri' => $attempt['item_uri'],
                            'pin' => $pin,
                            'item' => ['object_id' => $current['object_id'], 'version' => $this->vault->getObject($this->items->objectIdForUri($attempt['item_uri']))->head()],
                        ];
                    }
                }
            }

            $prepared = $this->transactions->prepare($attempt['id'], $attempt['item_uri'], ['original' => $path], $metadata);
            if ($prepared['status'] !== 'sealed') {
                return $prepared;
            }

            $result = $this->transactions->commit($attempt['id']);
            if ($result['status'] === 'complete') {
                $this->release($attempt['id']);
            }

            return [...$result, 'status' => $result['status'] === 'complete' ? 'preserved' : $result['status']];
        } finally {
            $staging->close();
        }
    }

    /**
     * Remove only redundant custody after the engine verifies canonical completion.
     *
     * A failure leaves the saved attempt retryable; it never erases Vault history.
     */
    private function release(string $transaction): void
    {
        $result = $this->transactions->releaseCustody($transaction);
        if ($result['status'] !== 'released') {
            throw new RuntimeException('Verified custody release failed for acquisition ' . $transaction);
        }
    }

    /**
     * Read the verified current Item, returning null only when its Object is absent.
     *
     * Existing damaged history is an error, never permission to mint a replacement.
     */
    private function current(string $uri): ?array
    {
        $id = $this->items->objectIdForUri($uri);

        return $this->vault->open()->hasObject($id->toString()) ? $this->items->read($id) : null;
    }

    /**
     * Restore this Input's source claims without advancing another Input's work.
     *
     * Sealed candidate metadata identifies relevant transactions even if later
     * journals or Item history are damaged. Those failures block this Input.
     * Unreadable unrelated Objects remain isolated and are never rewritten.
     */
    private function reassociate(string $input): void
    {
        $relevant = [];
        foreach ($this->transactions->candidates() as $candidate) {
            $source = $candidate['metadata']['stashd.filesystem.source'] ?? null;
            if (! is_array($source) || ($source['input_id'] ?? null) !== $input) {
                continue;
            }

            $relevant[$this->items->objectIdForUri($candidate['uri'])->toString()] = true;
            $result = $this->transactions->commit($candidate['id']);
            if ($result['status'] !== 'complete') {
                throw new RuntimeException('Relevant acquisition evidence requires recovery: ' . $candidate['id']);
            }

            $this->release($candidate['id']);
        }

        $claims = [];
        foreach ($this->vault->discoverObjects() as $object) {
            $id = $object['object_id'];
            if ($id === null || ! str_starts_with($id, 'urn:stashd:item:')) {
                continue;
            }

            $records = [];
            try {
                $urn = Urn::fromString($id);
                foreach ($this->vault->getObject($urn)->versionNames() as $version) {
                    $record = $this->items->read($urn, $version);
                    $source = $record['metadata']['stashd.filesystem.source'] ?? null;
                    if (! is_array($source) || ($source['input_id'] ?? null) !== $input) {
                        continue;
                    }

                    $relevant[$id] = true;
                    if (($source['version'] ?? null) !== 1 || ! is_string($source['path'] ?? null) || ! is_string($source['root'] ?? null)) {
                        throw new RuntimeException('Malformed canonical Filesystem provenance blocks identity recovery.');
                    }

                    $this->roots->relative($source['path']);
                    $records[] = ['source' => hash('sha256', $source['path']), 'uri' => $record['uri']];
                }
            } catch (\Exception $error) {
                if (isset($relevant[$id]) || $error instanceof \App\RecordsAndArchives\Storage\ArchiveReadFailure) {
                    throw $error;
                }

                continue;
            }

            foreach ($records as $record) {
                if (isset($claims[$record['source']]) && $claims[$record['source']] !== $record['uri']) {
                    throw new RuntimeException('Canonical source claims identify different Items.');
                }

                $claims[$record['source']] = $record['uri'];
            }
        }

        $this->store->restore($input, $claims);
    }
}
