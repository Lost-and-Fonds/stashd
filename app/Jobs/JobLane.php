<?php

declare(strict_types=1);

namespace App\Jobs;

/**
 * Worker lanes keep slow bulk work (downloads, transcodes, broadcast rebuilds)
 * from starving the short jobs a human is actively watching in the UI. Each
 * lane runs as its own serial loop; lane membership is a pure function of
 * JobIntent (see JobIntent::lane()), so no schema or queue changes are needed.
 * Bulk stays deliberately serial: parallel provider traffic multiplies
 * bot-detection risk and thrashes NAS I/O.
 */
enum JobLane: string
{
    case Interactive = 'interactive';
    case Discovery = 'discovery';
    case Bulk = 'bulk';

    /** @return list<JobIntent> */
    public function intents(): array
    {
        return array_values(array_filter(
            JobIntent::cases(),
            fn(JobIntent $intent): bool => $intent->lane() === $this,
        ));
    }
}
