<?php

declare(strict_types=1);

namespace App\Broadcasts;

final readonly class BroadcastTriggerService
{
    public function __construct() {}

    public function execute(BroadcastRecord $broadcast, string $reason = 'manual'): BroadcastTriggerResult
    {
        unset($broadcast, $reason);

        return new BroadcastTriggerResult(0, 0, 0, []);
    }
}
