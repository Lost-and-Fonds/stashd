<?php

declare(strict_types=1);

namespace App\Broadcasts;

/**
 * Optional presentation/action surface supplied by a Broadcast plugin.
 *
 * The application treats returned identifiers and values as opaque data. It
 * does not interpret a plugin's fields or action intents.
 */
interface BroadcastPluginPresentation
{
    /** @return list<array{id: string, label: string, value: mixed, kind?: string, link?: string}> */
    public function detailFields(BroadcastRecord $broadcast): array;

    /** @return list<array{id: string, label: string, intent: string, confirmation?: bool}> */
    public function actions(BroadcastRecord $broadcast): array;
}
