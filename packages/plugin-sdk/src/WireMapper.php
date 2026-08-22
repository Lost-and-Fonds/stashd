<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final class WireMapper
{
    /** @return array<string, mixed> */
    public static function publishRequest(PublishRequest $request): array
    {
        return [
            'reference' => $request->reference,
            'settings' => array_map([self::class, 'setting'], $request->settings),
            'sources' => array_map([self::class, 'source'], $request->sources),
            'items' => array_map([self::class, 'item'], $request->items),
        ];
    }

    /** @return array<string, mixed> */
    public static function publication(Publication $publication): array
    {
        return [
            'artifact' => ['reference' => $publication->artifact->reference, 'media-type' => $publication->artifact->mediaType, 'size-bytes' => $publication->artifact->sizeBytes],
            'files' => array_map(static fn(PublishedFile $file): array => ['item-id' => $file->itemId, 'source-reference' => $file->sourceReference, 'relative-path' => $file->relativePath], $publication->files),
            'published-metadata' => array_map([self::class, 'setting'], $publication->publishedMetadata),
        ];
    }

    /** @return array<string, mixed> */
    public static function pluginFailure(PluginFailure $failure): array
    {
        return ['tag' => $failure->code->value, 'value' => ['message' => $failure->error->message, 'retryable' => $failure->error->retryable]];
    }

    /** @return array<string, mixed> */
    public static function stagedArtifact(?StagedArtifact $artifact): array
    {
        if ($artifact === null) {
            throw new InvalidPluginResultException('staging did not return an artifact');
        }

        return ['reference' => $artifact->reference, 'media-type' => $artifact->mediaType, 'size-bytes' => $artifact->sizeBytes];
    }

    /** @return array<string, mixed> */
    private static function setting(Setting $setting): array
    {
        return ['key' => $setting->key, 'value' => $setting->value->toWire()];
    }

    /** @return array<string, mixed> */
    private static function source(Source $source): array
    {
        return ['reference' => $source->reference, 'settings' => array_map([self::class, 'setting'], $source->settings)];
    }

    /** @return array<string, mixed> */
    private static function item(Item $item): array
    {
        return [
            'id' => $item->id,
            'source-reference' => $item->sourceReference,
            'title' => $item->title,
            'description' => $item->description,
            'published-at' => $item->publishedAt,
            'duration-seconds' => $item->durationSeconds,
            'resources' => array_map(static fn(ItemResource $resource): array => [
                'reference' => $resource->reference, 'kind' => $resource->kind, 'derivation-key' => $resource->derivationKey,
                'url' => $resource->url, 'media-type' => $resource->mediaType, 'size-bytes' => $resource->sizeBytes,
            ], $item->resources),
        ];
    }
}
