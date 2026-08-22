<?php

declare(strict_types=1);

namespace App\MediaServers;

use App\Plugins\ExternalBroadcastPluginRegistry;
use App\Plugins\ExternalMediaServerClient;
use App\Plugins\PluginHostClient;
use App\Plugins\PluginHttpGrantFactory;

final readonly class MediaServerClientRegistry
{
    public function __construct(
        private ExternalBroadcastPluginRegistry $externalPlugins,
        private PluginHttpGrantFactory $grants,
    ) {
    }

    public function clientFor(MediaServerConnectionRecord $connection): MediaServerClient
    {
        $external = $this->externalPlugins->findByLogicalKey($connection->type->value);
        if ($external !== null && $external->available()) {
            return new ExternalMediaServerClient($external, new PluginHostClient($external->socketPath), $this->grants);
        }

        return $this->fallback($connection->type);
    }

    public function clientForType(MediaServerType $type): MediaServerClient
    {
        $external = $this->externalPlugins->findByLogicalKey($type->value);
        if ($external !== null && $external->available()) {
            return new ExternalMediaServerClient($external, new PluginHostClient($external->socketPath), $this->grants);
        }

        return $this->fallback($type);
    }

    private function fallback(MediaServerType $type): MediaServerClient
    {
        return new UnavailableMediaServerClient();
    }
}
