<?php

declare(strict_types=1);

namespace App\Broadcasts;

/**
 * Optional action surface supplied by a Broadcast plugin.
 *
 * Action IDs and payloads are opaque to Stashd. The application is responsible
 * only for authorization and durable job execution.
 */
interface BroadcastPluginActions
{
    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function invokeAction(BroadcastRecord $broadcast, string $intent, array $payload = []): array;
}
