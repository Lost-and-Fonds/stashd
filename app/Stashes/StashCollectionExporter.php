<?php

declare(strict_types=1);

namespace App\Stashes;

interface StashCollectionExporter
{
    public function key(): string;

    public function label(): string;

    /** @param list<StashCollectionEntry> $entries */
    public function export(array $entries): ExportedFile;
}
