<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Broadcasts\BroadcastPluginPresentation;
use App\Broadcasts\BroadcastPluginRegistry;
use App\Broadcasts\BroadcastRepository;
use App\Plugins\ExternalBroadcastPluginRegistry;

final readonly class StashCollectionExportService
{
    public function __construct(
        private StashRepository $stashes,
        private BroadcastRepository $broadcasts,
        private ExternalBroadcastPluginRegistry $plugins,
    ) {}

    /** @return list<StashCollectionExporter> */
    public function exporters(): array
    {
        return $this->plugins->collectionExporters();
    }

    public function exporter(string $key): ?StashCollectionExporter
    {
        foreach ($this->exporters() as $exporter) {
            if ($exporter->key() === $key) {
                return $exporter;
            }
        }

        return null;
    }

    /** @return list<StashCollectionEntry> */
    public function entries(): array
    {
        $entries = [];

        foreach ($this->stashes->list() as $stash) {
            foreach ($this->broadcasts->listForStash(StashId::fromPrimaryKey($stash->id)) as $broadcast) {
                $plugin = BroadcastPluginRegistry::findByKey($broadcast->type)?->plugin;

                if (! $plugin instanceof BroadcastPluginPresentation) {
                    continue;
                }

                foreach ($plugin->detailFields($broadcast) as $field) {
                    if (($field['kind'] ?? null) !== 'url' || ! is_string($field['value'] ?? null) || $field['value'] === '') {
                        continue;
                    }

                    $entries[] = new StashCollectionEntry($stash->name, $broadcast->type, $broadcast->name, $field['value']);
                }
            }
        }

        return $entries;
    }
}
