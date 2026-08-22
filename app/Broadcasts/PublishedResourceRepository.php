<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\Support\PrefixedUlid;
use App\Support\PrefixedUlidGenerator;
use Tempest\Database\PrimaryKey;

use function Tempest\Database\query;

final readonly class PublishedResourceRepository
{
    public function __construct(private PrefixedUlidGenerator $ids) {}

    public function create(PublishedResourceRecord $resource): PublishedResourceRecord
    {
        $resource->id = new PrimaryKey($this->ids->generate('publication')->toString());
        query(PublishedResourceRecord::class)->insert($resource)->execute();

        return $resource;
    }

    public function find(string $id): ?PublishedResourceRecord
    {
        if (! PrefixedUlid::isValid($id)) {
            return null;
        }

        return PublishedResourceRecord::findById(PrefixedUlid::parse($id)->toPrimaryKey());
    }

    public function save(PublishedResourceRecord $resource): PublishedResourceRecord
    {
        $resource->save();

        return $resource;
    }

    public function findByBroadcastAndPath(BroadcastId $broadcastId, string $relativePath): ?PublishedResourceRecord
    {
        $resource = PublishedResourceRecord::select()
            ->where('broadcastId', $broadcastId->toString())
            ->where('relativePath', $relativePath)
            ->first();

        return $resource instanceof PublishedResourceRecord ? $resource : null;
    }

    public function findByBroadcastAndAsset(BroadcastId $broadcastId, string $assetId, string $mediaType): ?PublishedResourceRecord
    {
        $resource = PublishedResourceRecord::select()
            ->where('broadcastId', $broadcastId->toString())
            ->where('assetId', $assetId)
            ->where('mediaType', $mediaType)
            ->first();

        return $resource instanceof PublishedResourceRecord ? $resource : null;
    }

    /** @return list<PublishedResourceRecord> */
    public function listForBroadcast(BroadcastId $broadcastId): array
    {
        return array_values(array_filter(
            PublishedResourceRecord::select()->where('broadcastId', $broadcastId->toString())->all(),
            static fn(mixed $resource): bool => $resource instanceof PublishedResourceRecord,
        ));
    }
}
