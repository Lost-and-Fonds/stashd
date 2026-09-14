<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Stashes\ExportedFile;
use App\Stashes\StashCollectionEntry;
use App\Stashes\StashCollectionExporter;

final readonly class ExternalStashCollectionExporter implements StashCollectionExporter
{
    /** @param array{key: string, label: string} $definition */
    public function __construct(private array $definition, private BroadcastPluginRuntime $runtime) {}

    public function key(): string
    {
        return $this->definition['key'];
    }

    public function label(): string
    {
        return $this->definition['label'];
    }

    /** @param list<StashCollectionEntry> $entries */
    public function export(array $entries): ExportedFile
    {
        $result = $this->runtime->exportCollection($this->key(), array_map(
            static fn(StashCollectionEntry $entry): array => [
                'stash-name' => $entry->stashName,
                'broadcast-key' => $entry->broadcastKey,
                'broadcast-name' => $entry->broadcastName,
                'public-url' => $entry->publicUrl,
            ],
            $entries,
        ));

        foreach (['filename', 'content-type', 'contents'] as $key) {
            if (! is_string($result[$key] ?? null) || $result[$key] === '') {
                throw new \RuntimeException('Collection exporter returned an invalid file.');
            }
        }

        return new ExportedFile($result['filename'], $result['content-type'], $result['contents']);
    }
}
