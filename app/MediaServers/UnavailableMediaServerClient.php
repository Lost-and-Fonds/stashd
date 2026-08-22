<?php

declare(strict_types=1);

namespace App\MediaServers;

/** Explicit failure when an optional external implementation is unavailable. */
final readonly class UnavailableMediaServerClient implements MediaServerClient
{
    public function testConnection(MediaServerConnectionRecord $connection, string $token): MediaServerStatus
    {
        unset($connection, $token);
        return new MediaServerStatus(false, 'The external Broadcast plugin is unavailable.');
    }

    public function listLibraries(MediaServerConnectionRecord $connection, string $token): array
    {
        unset($connection, $token);
        throw MediaServerException::withCode('media_server_unavailable', 'The external Broadcast plugin is unavailable.');
    }

    public function triggerScan(
        MediaServerConnectionRecord $connection,
        string $token,
        MediaServerLibraryRef $library,
        ?string $path = null,
    ): MediaServerTriggerResult {
        unset($connection, $token, $library, $path);
        return new MediaServerTriggerResult(false, 'The external Broadcast plugin is unavailable.', null);
    }
}
