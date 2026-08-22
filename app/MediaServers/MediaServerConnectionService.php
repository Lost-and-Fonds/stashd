<?php

declare(strict_types=1);

namespace App\MediaServers;

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
        private MediaServerClientRegistry $clients,
        private MediaServerConnectionSecrets $tokens,
        private SecretsService $secrets,
        private SecretRepository $secretRecords,
        private StateTransitionService $transitions,
    ) {
    }

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
            $record->settings = MediaServerLibrarySelection::fromArray($settings);
        }

        if ($token !== null && trim($token) !== '') {
            $this->storeToken($record, trim($token));
        }

        if ($state !== null && $record->state !== $state) {
            $this->transitions->transitionMediaServerConnection($record, $state);
        }

        return $this->connections->save($record);
    }

    public function testConnection(PrefixedUlid $id): MediaServerStatus
    {
        $record = $this->connections->find($id)
            ?? throw MediaServerException::withCode('media_server_not_found', 'Media server connection not found.');

        $token = $this->requireToken($record);
        $status = $this->clients->clientFor($record)->testConnection($record, $token);

        $record->lastCheckedAt = DateTime::now(Timezone::UTC);
        $record->lastError = $status->ok ? null : $status->message;

        if ($status->ok) {
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

    /** @return list<MediaServerLibraryRef> */
    public function listLibraries(PrefixedUlid $id): array
    {
        $record = $this->connections->find($id)
            ?? throw MediaServerException::withCode('media_server_not_found', 'Media server connection not found.');

        $token = $this->requireToken($record);

        return $this->clients->clientFor($record)->listLibraries($record, $token);
    }

    /** @return array<string, mixed> */
    public function settings(MediaServerConnectionRecord $record): array
    {
        return $record->settings?->toArray() ?? [];
    }

    public function libraryFromSettings(MediaServerConnectionRecord $record): ?MediaServerLibraryRef
    {
        return $record->settings?->toLibraryRef();
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
}
