<?php

declare(strict_types=1);

namespace App\System\Secret;

use RuntimeException;
use SensitiveParameter;
use Tempest\Cryptography\Encryption\EncryptedData;
use Tempest\Cryptography\Encryption\Encrypter;
use Tempest\Cryptography\Encryption\Exceptions\EncryptionKeyWasInvalid;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class SecretsService
{
    public function __construct(
        private Encrypter $encrypter,
        private SecretRepository $secrets,
    ) {}

    /** @param array<string, mixed>|null $metadata */
    public function put(
        string $key,
        SecretType $type,
        #[SensitiveParameter]
        string $plaintext,
        ?array $metadata = null,
    ): void {
        $encrypted = $this->encrypt($plaintext);
        $existing = $this->secrets->findByKey($key);

        if ($existing === null) {
            $this->secrets->create(
                key: $key,
                type: $type,
                encryptedValue: $encrypted['value'],
                nonce: $encrypted['nonce'],
                metadata: $metadata,
            );

            return;
        }

        $existing->type = $type;
        $existing->encryptedValue = $encrypted['value'];
        $existing->nonce = $encrypted['nonce'];
        $existing->metadata = $metadata ?? $existing->metadata;
        $existing->revokedAt = null;
        $this->secrets->save($existing);
    }

    public function get(string $key): ?string
    {
        $record = $this->secrets->findByKey($key);

        if ($record === null) {
            return null;
        }

        $plaintext = $this->decrypt($record->encryptedValue);
        $record->lastUsedAt = DateTime::now(Timezone::UTC);
        $this->secrets->save($record);

        return $plaintext;
    }

    public function revoke(string $key): void
    {
        $record = $this->secrets->findByKey($key);

        if ($record === null) {
            return;
        }

        $record->revokedAt = DateTime::now(Timezone::UTC);
        $this->secrets->save($record);
    }

    /** @return array{value: string, nonce: string} */
    private function encrypt(#[SensitiveParameter] string $plaintext): array
    {
        try {
            $encrypted = $this->encrypter->encrypt($plaintext);
        } catch (EncryptionKeyWasInvalid $exception) {
            throw new RuntimeException(
                'Cannot encrypt secrets: Tempest signing key is missing or invalid. Run `php tempest key:generate`.',
                previous: $exception,
            );
        }

        return [
            'value' => $encrypted->serialize(),
            'nonce' => base64_encode($encrypted->iv),
        ];
    }

    private function decrypt(string $encryptedValue): string
    {
        try {
            return $this->encrypter->decrypt(EncryptedData::unserialize($encryptedValue));
        } catch (EncryptionKeyWasInvalid $exception) {
            throw new RuntimeException(
                'Cannot decrypt secrets: Tempest signing key changed or is invalid.',
                previous: $exception,
            );
        }
    }

    /** Redact likely secret material from log/error strings. */
    public function redact(string $value): string
    {
        $redacted = preg_replace(
            '/\b(?:Bearer\s+)?[A-Za-z0-9_\-]{20,}\b/',
            '[REDACTED]',
            $value,
        );

        return $redacted ?? $value;
    }
}
