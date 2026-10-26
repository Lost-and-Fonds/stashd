<?php

declare(strict_types=1);

namespace App\Integration;

use App\Integration\DTO\PublishedFile;
use App\RecordsAndArchives\AssetArchive;
use InvalidArgumentException;
use League\Uri\Urn;
use RuntimeException;
use WeakMap;

/**
 * Limit one publisher to delivered exact Assets and provisional bounded reports.
 *
 * @phpstan-import-type FrozenAsset from BroadcastStore
 * @phpstan-import-type FrozenItem from BroadcastStore
 */
final class BroadcastInvocation
{
    public const int MAXIMUM_BATCH = 100;

    private bool $active = true;
    private bool $eof = false;
    private int $position = -1;
    private int $reports = 0;
    private int $files = 0;
    /**
     * @var array<string, FrozenAsset> Assets delivered during this invocation only.
     */
    private array $grants = [];
    /**
     * @var WeakMap<BroadcastStream, bool> Live readers revoked when the invocation ends.
     */
    private WeakMap $streams;

    /**
     * Bind this capability object to one persisted attempt without granting any reads yet.
     */
    public function __construct(
        private BroadcastStore $store,
        private AssetArchive $assets,
        private string $run,
        private string $attempt,
    ) {
        $this->streams = new WeakMap();
    }

    /**
     * Deliver up to 100 selected Items and grant their Assets; null is sticky EOF.
     */
    public function next(int $maximum): ?array
    {
        $this->authorize();
        if ($maximum < 1) {
            throw new InvalidArgumentException('Collection batch size must be positive.');
        }

        if ($this->eof) {
            return null;
        }

        $rows = $this->store->selection($this->run, $this->position, min($maximum, self::MAXIMUM_BATCH));
        if ($rows === []) {
            $this->eof = true;

            return null;
        }

        $items = [];
        foreach ($rows as $row) {
            /** @var FrozenItem $record */
            $record = json_decode($row['record'], true, flags: JSON_THROW_ON_ERROR);
            $delivered = [];
            foreach ($record['assets'] as $asset) {
                $reference = bin2hex(random_bytes(32));
                $this->grants[$reference] = $asset;
                $delivered[] = ['id' => $asset['id'], 'reference' => $reference, 'size_bytes' => $asset['size'], 'media_type' => null, 'metadata' => []];
            }

            $items[] = ['id' => $record['id'], 'assets' => $delivered, 'metadata' => $record['metadata']];
            $this->position = (int) $row['position'];
        }

        return $items;
    }

    /**
     * Open only a delivered reference and clamp its range to the verified representation.
     *
     * The invocation owns and closes the reader, including when a publisher throws.
     */
    public function openAsset(string $reference, int $offset = 0, ?int $length = null): BroadcastStream
    {
        $this->authorize();
        $asset = $this->grants[$reference] ?? throw new RuntimeException('denied: Asset was not delivered by this invocation.');
        if ($offset < 0 || $offset > $asset['size'] || $length !== null && $length < 0) {
            throw new RuntimeException('denied: Invalid Asset range.');
        }

        $stream = $this->assets->readStream(Urn::fromString($asset['id']), $asset['version'], $asset['path']);
        if (fseek($stream, $offset) !== 0) {
            fclose($stream);
            throw new RuntimeException('Could not seek preserved Asset.');
        }

        $extent = $asset['size'] - $offset;
        $reader = new BroadcastStream($stream, $length === null ? $extent : min($length, $extent), $this->authorize(...));
        $this->streams[$reader] = true;

        return $reader;
    }

    /**
     * Save a nonempty bounded file batch provisionally; duplicate paths reject the batch.
     */
    public function reportFiles(PublishedFile ...$files): void
    {
        $this->batch(count($files));
        $records = array_map(static fn (PublishedFile $file): array => get_object_vars($file), $files);
        $this->store->report($this->run, $this->attempt, $this->reports, 'file', $records);
        $this->reports += count($files);
        $this->files += count($files);
    }

    /**
     * Retain ordered opaque JSON object facets without interpreting their domain schema.
     */
    public function reportMetadata(array ...$facets): void
    {
        $this->batch(count($facets));
        foreach ($facets as $facet) {
            if (
                ! is_string($facet['schema'] ?? null)
                || $facet['schema'] === ''
                || ! is_string($facet['json'] ?? null)
                || ! is_object(json_decode($facet['json'], flags: JSON_THROW_ON_ERROR))
            ) {
                throw new InvalidArgumentException('Metadata requires a schema and JSON object.');
            }
        }

        $this->store->report($this->run, $this->attempt, $this->reports, 'metadata', $facets);
        $this->reports += count($facets);
    }

    /**
     * Require observed collection EOF and a valid final file-completeness assertion.
     */
    public function finish(FileReportStatus $status): void
    {
        $this->authorize();
        if (! $this->eof || $status === FileReportStatus::NotApplicable && $this->files !== 0) {
            throw new RuntimeException('Publication requires collection EOF and a consistent complete file report.');
        }
    }

    /**
     * Revoke references and close every reader, even if a publisher retained this object.
     */
    public function close(): void
    {
        $this->active = false;
        $this->grants = [];
        foreach ($this->streams as $stream => $live) {
            $stream->close();
        }

        $this->streams = new WeakMap();
    }

    /**
     * Reject invalid batch sizes before writing any provisional records.
     */
    private function batch(int $count): void
    {
        $this->authorize();
        if ($count < 1 || $count > self::MAXIMUM_BATCH) {
            throw new InvalidArgumentException('Report between 1 and 100 records per batch.');
        }
    }

    /**
     * Refuse capability calls after the owning publication has ended.
     */
    private function authorize(): void
    {
        if (! $this->active) {
            throw new RuntimeException('Invocation has ended.');
        }
    }
}
