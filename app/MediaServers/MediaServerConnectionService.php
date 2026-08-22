<?php

declare(strict_types=1);

namespace App\MediaServers;

use App\Plugins\ExternalBroadcastPluginRegistry;
use App\Plugins\PluginHostClient;
use App\Plugins\PluginHttpGrantFactory;
use App\Support\PrefixedUlid;
use App\System\Secret\SecretRepository;
use App\System\Secret\SecretsService;
use App\System\Secret\SecretType;
use App\System\State\StateTransitionService;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class MediaServerConnectionService
{
    public function __construct(
        private MediaServerConnectionRepository $connections,
        private MediaServerConnectionSecrets $tokens,
        private ExternalBroadcastPluginRegistry $plugins,
        private PluginHttpGrantFactory $grants,
        private SecretsService $secrets,
        private SecretRepository $secretRecords,
        private StateTransitionService $transitions,
    ) {
    }

    /** @param array<string, mixed>|null $settings */
    public function create(
        string $type,
        string $name,
        string $baseUri,
        #[\SensitiveParameter] ?string $token = null,
        ?array $settings = null,
    ): MediaServerConnectionRecord {
        $record = $this->connections->create(
            type: $type,
            name: $name,
            baseUri: rtrim(trim($baseUri), '/'),
            settings: $settings,
        );

        if ($token !== null && trim($token) !== '') {
            $this->storeToken($record, trim($token));
        }

        return $record;
    }

    /** @param array<string, mixed>|null $settings */
    public function update(
        PrefixedUlid $id,
        ?string $name = null,
        ?string $baseUri = null,
        ?array $settings = null,
        #[\SensitiveParameter] ?string $token = null,
        ?MediaServerConnectionState $state = null,
    ): MediaServerConnectionRecord {
        $record = $this->connections->find($id)
            ?? throw MediaServerException::withCode('media_server_not_found', 'Media server connection not found.');

        if ($name !== null) {
            $record->name = $name;
        }

        if ($baseUri !== null) {
            $record->baseUri = rtrim(trim($baseUri), '/');
        }

        if ($settings !== null) {
            /** @var array<string, mixed> $normalizedSettings */
            $normalizedSettings = $settings;
            $record->settings = $normalizedSettings;
        }

        if ($token !== null && trim($token) !== '') {
            $this->storeToken($record, trim($token));
        }

        if ($state !== null && $record->state !== $state) {
            $this->transitions->transitionMediaServerConnection($record, $state);
        }

        return $this->connections->save($record);
    }

    /** @return array<string, mixed> */
    public function testConnection(PrefixedUlid $id): array
    {
        $record = $this->connections->find($id)
            ?? throw MediaServerException::withCode('media_server_not_found', 'Media server connection not found.');

        $token = $this->requireToken($record);
        $status = ['ok' => false, 'message' => 'Connection test failed.', 'server_name' => null, 'version' => null];
        try {
            $result = $this->invoke($record, 'test_connection', $token);
            $values = $this->values($result);
            $status = [
                'ok' => ($values['ok'] ?? 'false') === 'true',
                'message' => $values['message'] ?? 'External connection test completed.',
                'server_name' => $values['server_name'] ?? null,
                'version' => $values['version'] ?? null,
            ];
        } catch (MediaServerException $exception) {
            $status = ['ok' => false, 'message' => $exception->getMessage(), 'server_name' => null, 'version' => null];
        }

        $record->lastCheckedAt = DateTime::now(Timezone::UTC);
        $record->lastError = $status['ok'] ? null : $status['message'];

        if ($status['ok']) {
            if ($record->state !== MediaServerConnectionState::Ready) {
                $this->transitions->transitionMediaServerConnection($record, MediaServerConnectionState::Ready);
            } else {
                $this->connections->save($record);
            }
        } elseif ($record->state !== MediaServerConnectionState::Failed) {
            $this->transitions->transitionMediaServerConnection($record, MediaServerConnectionState::Failed);
        } else {
            $this->connections->save($record);
        }

        return $status;
    }

    /** @return list<array{id: string, name: string, type: ?string}> */
    public function listLibraries(PrefixedUlid $id): array
    {
        $record = $this->connections->find($id)
            ?? throw MediaServerException::withCode('media_server_not_found', 'Media server connection not found.');

        $token = $this->requireToken($record);

        $result = $this->invoke($record, 'list_libraries', $token);
        $choices = $result['choices'] ?? null;
        if (! is_array($choices)) {
            throw MediaServerException::withCode('media_server_list_libraries_failed', 'External library discovery returned invalid choices.');
        }

        $libraries = [];
        foreach ($choices as $choice) {
            if (is_array($choice) && is_string($choice['value'] ?? null) && is_string($choice['label'] ?? null)) {
                $libraries[] = ['id' => $choice['value'], 'name' => $choice['label'], 'type' => null];
            }
        }

        return $libraries;
    }

    /** @return array<string, mixed> */
    public function settings(MediaServerConnectionRecord $record): array
    {
        return $record->settings ?? [];
    }

    private function storeToken(MediaServerConnectionRecord $record, string $token): void
    {
        $secretKey = 'media_server:' . (string) $record->id . ':token';
        $this->secrets->put($secretKey, SecretType::MediaServerToken, $token);

        $secret = $this->secretRecords->findByKey($secretKey)
            ?? throw MediaServerException::withCode('media_server_token_store_failed', 'Failed to store media server token.');

        $record->tokenSecretId = (string) $secret->id;
        $this->connections->save($record);
    }

    private function requireToken(MediaServerConnectionRecord $record): string
    {
        $token = $this->tokens->resolve($record);

        if ($token === null || trim($token) === '') {
            throw MediaServerException::withCode('media_server_token_missing', 'Media server token is not configured.');
        }

        return $token;
    }

    /** @return array<string, mixed> */
    private function invoke(MediaServerConnectionRecord $connection, string $operationKey, string $token): array
    {
        $definition = $this->plugins->findByLogicalKey($connection->type);
        $operation = $definition?->operations[$operationKey] ?? null;
        if ($definition === null || ! $definition->available() || $operation === null) {
            throw MediaServerException::withCode('media_server_operation_unsupported', 'External connection operation is unavailable.');
        }

        $stage = sys_get_temp_dir() . '/stashd-external-connection-' . bin2hex(random_bytes(6));
        if (! mkdir($stage, 0o775, true) && ! is_dir($stage)) {
            throw MediaServerException::withCode('media_server_unavailable', 'Could not create operation staging directory.');
        }

        try {
            return (new PluginHostClient($definition->socketPath))->broadcastOperation(
                $definition->componentPath,
                $stage,
                [
                    'reference' => (string) $connection->id,
                    'settings' => [
                        ['key' => 'server_url', 'value' => ['kind' => 'text', 'value' => $connection->baseUri]],
                        ['key' => 'credential_name', 'value' => ['kind' => 'text', 'value' => $definition->credentialName ?? '']],
                        ...$this->settingEntries($connection->settings ?? []),
                    ],
                    'items' => [],
                ],
                $operation,
                $this->grants->forConnection($definition, $connection, $token),
                getenv('STASHD_BROADCAST_HTTP_FIXTURE_DIR') ?: null,
            );
        } catch (MediaServerException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw MediaServerException::withCode('media_server_unavailable', 'External connection operation failed.', $exception);
        } finally {
            foreach (glob($stage . '/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($stage);
        }
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, string>
     */
    private function values(array $result): array
    {
        /** @var array<string, string> $values */
        $values = [];
        /** @var list<array<string, mixed>> $settings */
        $settings = is_array($result['values'] ?? null) ? $result['values'] : [];
        foreach ($settings as $setting) {
            if (is_string($setting['key'] ?? null) && is_scalar($setting['value'] ?? null)) {
                $values[$setting['key']] = (string) $setting['value'];
            }
        }
        return $values;
    }

    /**
     * @param array<string, mixed> $settings
     * @return list<array{key: string, value: array{kind: 'text', value: string}}>
     */
    private function settingEntries(array $settings): array
    {
        /** @var list<array{key: string, value: array{kind: 'text', value: string}}> $entries */
        $entries = [];
        foreach ($settings as $key => $value) {
            if (is_scalar($value)) {
                $entries[] = ['key' => $key, 'value' => ['kind' => 'text', 'value' => (string) $value]];
            }
        }
        return $entries;
    }
}
