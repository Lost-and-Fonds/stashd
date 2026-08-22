<?php

declare(strict_types=1);

namespace App\Plugins;

use App\MediaServers\MediaServerClient;
use App\MediaServers\MediaServerConnectionRecord;
use App\MediaServers\MediaServerException;
use App\MediaServers\MediaServerLibraryRef;
use App\MediaServers\MediaServerStatus;
use App\MediaServers\MediaServerTriggerResult;
use RuntimeException;

/** Generic adapter for connection operations declared by an external plugin. */
final readonly class ExternalMediaServerClient implements MediaServerClient
{
    public function __construct(
        private ExternalBroadcastPluginDefinition $definition,
        private PluginHostClient $host,
        private PluginHttpGrantFactory $grants,
    ) {
    }

    public function testConnection(MediaServerConnectionRecord $connection, string $token): MediaServerStatus
    {
        try {
            $result = $this->invoke($connection, 'test_connection', $token);
        } catch (MediaServerException $exception) {
            return new MediaServerStatus(false, $exception->getMessage());
        }
        $values = $this->values($result);
        return new MediaServerStatus(
            ok: ($values['ok'] ?? 'false') === 'true',
            message: $values['message'] ?? 'External connection test completed.',
            serverName: $values['server_name'] ?? null,
            version: $values['version'] ?? null,
        );
    }

    public function listLibraries(MediaServerConnectionRecord $connection, string $token): array
    {
        $result = $this->invoke($connection, 'list_libraries', $token);
        $choices = $result['choices'] ?? null;
        if (! is_array($choices)) {
            throw MediaServerException::withCode('media_server_list_libraries_failed', 'External library discovery returned invalid choices.');
        }
        $libraries = [];
        foreach ($choices as $choice) {
            if (! is_array($choice) || ! is_string($choice['value'] ?? null) || ! is_string($choice['label'] ?? null)) {
                continue;
            }
            $libraries[] = new MediaServerLibraryRef((string) $choice['value'], (string) $choice['label'], null);
        }
        return $libraries;
    }

    public function triggerScan(
        MediaServerConnectionRecord $connection,
        string $token,
        MediaServerLibraryRef $library,
        ?string $path = null,
    ): MediaServerTriggerResult {
        unset($library, $path);
        try {
            $this->invoke($connection, 'refresh_library', $token);
        } catch (MediaServerException $exception) {
            return new MediaServerTriggerResult(false, $exception->getMessage(), null);
        }

        return new MediaServerTriggerResult(true, 'External library refresh completed.', null);
    }

    /** @return array<string, mixed> */
    private function invoke(MediaServerConnectionRecord $connection, string $operationKey, string $token): array
    {
        $operation = $this->definition->operations[$operationKey] ?? null;
        if ($operation === null) {
            throw MediaServerException::withCode('media_server_operation_unsupported', 'External connection operation is unavailable.');
        }
        $stage = sys_get_temp_dir() . '/stashd-external-connection-' . bin2hex(random_bytes(6));
        if (! mkdir($stage, 0o775, true) && ! is_dir($stage)) {
            throw new RuntimeException('Could not create external connection staging directory.');
        }
        try {
            try {
                $result = $this->host->broadcastOperation(
                    $this->definition->componentPath,
                    $stage,
                    [
                    'reference' => (string) $connection->id,
                    'settings' => [
                        ['key' => 'server_url', 'value' => ['kind' => 'text', 'value' => $connection->baseUri]],
                        ['key' => 'credential_name', 'value' => ['kind' => 'text', 'value' => $this->definition->credentialName ?? '']],
                        ...array_map(
                            static fn (string $key, string $value): array => [
                                'key' => $key,
                                'value' => ['kind' => 'text', 'value' => $value],
                            ],
                            array_keys($connection->settings?->toArray() ?? []),
                            array_values($connection->settings?->toArray() ?? []),
                        ),
                    ],
                    'items' => [],
                ],
                    $operation,
                    $this->grants->forConnection($this->definition, $connection, $token),
                    getenv('STASHD_BROADCAST_HTTP_FIXTURE_DIR') ?: null,
                );
            } catch (\Throwable $exception) {
                throw MediaServerException::withCode('media_server_unavailable', 'External connection operation failed.', $exception);
            }
            return $result;
        } finally {
            foreach (glob($stage . '/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($stage);
        }
    }

    /** @return array<string, string> */
    /** @param array<string, mixed> $result
     *  @return array<string, string>
     */
    private function values(array $result): array
    {
        $values = [];
        $settings = is_array($result['values'] ?? null) ? $result['values'] : [];
        foreach ($settings as $setting) {
            if (is_array($setting) && is_string($setting['key'] ?? null) && is_string($setting['value'] ?? null)) {
                $values[$setting['key']] = $setting['value'];
            }
        }
        return $values;
    }
}
