<?php

namespace App\RecordsAndArchives;

use JsonException;
use League\Uri\Exceptions\SyntaxError;
use League\Uri\Uri;
use League\Uri\Urn;
use Ottosmops\Ocfl\OcflObject;
use Symfony\Component\Uid\Uuid;
use UnexpectedValueException;

/** Durable Item records; no catalogue or process-local identity registry. */
final readonly class ItemRecords
{
    private const string FORMAT = 'stashd-item';
    private const string IDENTITY_POLICY = 'exact-uri-v1';
    private const string RECORD_PATH = 'item.json';

    public function __construct(
        private Vault $vault,
    ) {}

    /**
     * @param array<array-key, mixed> $metadata Opaque JSON values, owned by the producer.
     * @param array<array-key, mixed> $pins Nonempty pins require the future Asset preservation service.
     */
    public function record(string $uri, array $metadata = [], array $pins = []): OcflObject
    {
        $uuid = $this->uuidForUri($uri);
        $id = $this->vault->urnFromUuid(IdentifierType::Item, $uuid);
        if ($pins !== []) {
            throw new InvalidItemRecord('Asset pins are unsupported until canonical Asset preservation can establish ownership.');
        }

        try {
            // Encode first to reject recursion, invalid UTF-8, resources and non-finite numbers.
            $json = json_encode([
                'format' => self::FORMAT,
                'version' => 1,
                'identity_policy' => self::IDENTITY_POLICY,
                'object_id' => $id->toString(),
                'uri' => $uri,
                'metadata' => $metadata,
                'pins' => [],
            ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $exception) {
            throw new InvalidItemRecord('Item metadata must contain valid JSON values.', previous: $exception);
        }
        $this->validateMetadata($metadata);
        $files = [self::RECORD_PATH => ['contents' => $json]];
        $root = $this->vault->open();

        if (! $root->hasObject($id->toString()) || $root->getObject($id->toString())->head() === '') {
            // shortcut: concurrent first creators may race here; Vault rejects the loser under its write lock. Retry the record call.
            return $this->vault->createObject(IdentifierType::Item, $uuid, $files);
        }

        // Refuse to append to an unrelated or incompatible Object at this identity.
        if ($this->read($id)['uri'] !== $uri) {
            throw new UnexpectedValueException('Item identity is already bound to a different canonical URI.');
        }

        return $this->vault->updateObject($id, $files);
    }

    /** @return array{format: string, version: int, identity_policy: string, object_id: string, uri: string, metadata: array, pins: array} */
    public function read(Urn $objectId, ?string $version = null): array
    {
        $object = $this->vault->open()->getObject($objectId->toString());
        $record = json_decode(
            $this->vault->readObjectContent($objectId, $version ?? $object->head(), self::RECORD_PATH),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
        if (
            ! is_array($record)
            || ($record['format'] ?? null) !== self::FORMAT
            || ($record['version'] ?? null) !== 1
            || ($record['identity_policy'] ?? null) !== self::IDENTITY_POLICY
            || ($record['object_id'] ?? null) !== $objectId->toString()
            || ! is_string($record['uri'] ?? null)
            || ! is_array($record['metadata'] ?? null)
            || ($record['pins'] ?? null) !== []
        ) {
            throw new UnexpectedValueException('Unsupported or inconsistent archived Item record.');
        }
        if ($this->objectIdForUri($record['uri'])->toString() !== $objectId->toString()) {
            throw new UnexpectedValueException('Archived Item URI does not match its Object identity.');
        }

        return [
            'format' => self::FORMAT,
            'version' => 1,
            'identity_policy' => self::IDENTITY_POLICY,
            'object_id' => $objectId->toString(),
            'uri' => $record['uri'],
            'metadata' => $record['metadata'],
            'pins' => [],
        ];
    }

    public function objectIdForUri(string $uri): Urn
    {
        return $this->vault->urnFromUuid(IdentifierType::Item, $this->uuidForUri($uri));
    }

    private function uuidForUri(string $uri): Uuid
    {
        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:.+$/D', $uri) || preg_match('/[^a-zA-Z0-9._~:\/?#\[\]@!$&\x27()*+,;=%-]/', $uri) || preg_match('/%(?![a-fA-F0-9]{2})/', $uri)) {
            throw new InvalidItemRecord('Canonical Item identity must be an absolute, ASCII URI with valid percent escapes.');
        }
        try {
            Uri::new($uri);
        } catch (SyntaxError $exception) {
            throw new InvalidItemRecord('Canonical Item URI is invalid.', previous: $exception);
        }

        // Parse only for validation: never hash the parser's normalized serialization.
        return Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_URL), 'stashd:item:exact-uri-v1:' . $uri);
    }

    /** @param array<array-key, mixed> $metadata */
    private function validateMetadata(array $metadata): void
    {
        foreach ($metadata as $value) {
            if (is_array($value)) {
                $this->validateMetadata($value);
            } elseif (! is_null($value) && ! is_scalar($value)) {
                throw new InvalidItemRecord('Item metadata must contain JSON values, not PHP objects.');
            }
        }
    }
}
