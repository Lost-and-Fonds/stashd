<?php

declare(strict_types=1);

namespace App\Broadcasts;

/** Optional declarative controls whose values are scoped to an opaque source. */
interface BroadcastPluginSourceOptions
{
    /** @return list<UiControl> */
    public function sourceUiControls(): array;
}
