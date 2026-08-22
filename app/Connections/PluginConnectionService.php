<?php

declare(strict_types=1);

namespace App\Connections;

use App\MediaServers\MediaServerConnectionRecord;
use App\MediaServers\MediaServerConnectionRepository;
use App\MediaServers\MediaServerConnectionSecrets;
use App\Plugins\ExternalBroadcastPluginRegistry;
use App\Plugins\PluginHostClient;
use App\Plugins\PluginHttpGrantFactory;
use App\Support\PrefixedUlid;
use App\System\Secret\SecretRepository;
use App\System\Secret\SecretsService;
use App\System\Secret\SecretType;

final readonly class PluginConnectionService
{
    public function __construct(
        private MediaServerConnectionRepository $connections,
        private MediaServerConnectionSecrets $tokens,
        private ExternalBroadcastPluginRegistry $plugins,
        private PluginHttpGrantFactory $grants,
        private SecretsService $secrets,
        private SecretRepository $secretRecords,
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
    ): MediaServerConnectionRecord {
        $record = $this->connections->find($id)
            ?? throw ConnectionException::withCode('connection_not_found', 'Connection not found.');

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

        return $this->connections->save($record);
    }

    /**
     * @param array<string, scalar> $payload
     * @return array<string, mixed>
     */
    public function invokeOperation(PrefixedUlid $id, string $operationKey, array $payload = []): array
    {
        $record = $this->connections->find($id)
            ?? throw ConnectionException::withCode('connection_not_found', 'Connection not found.');

        $token = $this->requireToken($record);
        return $this->invoke($record, $operationKey, $token, $payload);
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
            ?? throw ConnectionException::withCode('connection_credential_store_failed', 'Failed to store connection credential.');

        $record->tokenSecretId = (string) $secret->id;
        $this->connections->save($record);
    }

    private function requireToken(MediaServerConnectionRecord $record): string
    {
        $token = $this->tokens->resolve($record);

        if ($token === null || trim($token) === '') {
            throw ConnectionException::withCode('connection_credential_missing', 'Connection credential is not configured.');
        }

        return $token;
    }

    /**
     * @param array<string, scalar> $payload
     * @return array<string, mixed>
     */
    private function invoke(MediaServerConnectionRecord $connection, string $operationKey, string $token, array $payload = []): array
    {
        $definition = $this->plugins->findByLogicalKey($connection->type);
        $operation = $definition?->operations[$operationKey] ?? null;
        if ($definition === null || ! $definition->available() || $operation === null) {
            throw ConnectionException::withCode('connection_operation_unavailable', 'Connection operation is unavailable.');
        }

        $stage = sys_get_temp_dir() . '/stashd-external-connection-' . bin2hex(random_bytes(6));
        if (! mkdir($stage, 0o775, true) && ! is_dir($stage)) {
            throw ConnectionException::withCode('connection_unavailable', 'Could not create operation staging directory.');
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
                        ...$this->settingEntries($payload),
                    ],
                    'items' => [],
                ],
                $operation,
                $this->grants->forConnection($definition, $connection, $token),
                getenv('STASHD_BROADCAST_HTTP_FIXTURE_DIR') ?: null,
            );
        } catch (ConnectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw ConnectionException::withCode('connection_unavailable', 'Connection operation failed.', $exception);
        } finally {
            foreach (glob($stage . '/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($stage);
        }
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
