<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Connections\ConnectionRecord;

/** Builds generic invocation grants from an existing configured connection. */
final readonly class PluginHttpGrantFactory
{
    public function __construct() {}

    /** @return list<PluginHttpGrant> */
    public function forConnection(
        ExternalBroadcastPluginDefinition $definition,
        ConnectionRecord $connection,
        string $token,
    ): array {
        if ($definition->credentialName === null || $definition->credentialParameter === null) {
            return [];
        }

        return [new PluginHttpGrant(
            allowedPrefixes: [rtrim($connection->baseUri, '/') . '/'],
            credential: new PluginCredentialGrant(
                name: $definition->credentialName,
                value: $token,
                parameter: $definition->credentialParameter,
                placement: $definition->credentialPlacement,
            ),
        )];
    }
}
