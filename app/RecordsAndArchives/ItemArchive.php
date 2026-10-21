<?php

namespace App\RecordsAndArchives;

use JsonException;
use League\Uri\Urn;
use Ottosmops\Ocfl\OcflObject;
use Ottosmops\Ocfl\Validation\OcflException;
use OutOfBoundsException;
use Symfony\Component\Uid\Uuid;
use UnexpectedValueException;

/** Commits and reads immutable Item revisions in the canonical Vault. */
final readonly class ItemArchive
{
    private const string FORMAT = 'stashd-item';
    private const string IDENTITY_POLICY = 'exact-uri-v1';
    private const string RECORD_PATH = 'item.json';

    public function __construct(
        private Vault $vault,
        private ItemIdentity $identity,
        private AssetArchive $assets,
    ) {}

    /**
     * @param array<array-key, mixed> $metadata Opaque JSON values, owned by the producer.
     * @param array<array-key, mixed> $pins Exact Asset Object/version references, verified before publication.
     */
    public function commitRevision(string $uri, array $metadata = [], array $pins = []): OcflObject
    {
        $uuid = $this->identity->uuidForUri($uri);
        $id = $this->vault->urnFromUuid(IdentifierType::Item, $uuid);
        $pins = $this->pinReferences($pins);
        foreach ($pins as $pin) {
            try {
                $asset = $this->assets->inspect(Urn::fromString($pin['object_id']), $pin['version']);
            } catch (OcflException|OutOfBoundsException|UnexpectedValueException|JsonException|InvalidAsset $exception) {
                throw new InvalidItemRevision('Pinned Asset version is missing, incompatible or corrupt.', previous: $exception);
            }
            if (($asset['item_uri'] ?? null) !== $uri || ($asset['item_object_id'] ?? null) !== $id->toString()) {
                throw new InvalidItemRevision('Pinned Asset belongs to a different canonical Item.');
            }
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
                'pins' => $pins,
            ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $exception) {
            throw new InvalidItemRevision('Item metadata must contain valid JSON values.', previous: $exception);
        }
        $this->validateMetadata($metadata);
        $files = [self::RECORD_PATH => ['contents' => $json]];
        $root = $this->vault->open();

        if (! $root->hasObject($id->toString()) || $root->getObject($id->toString())->head() === '') {
            // shortcut: concurrent first creators may race here; Vault rejects the loser under its write lock. Retry the commitRevision call.
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
        $version ??= $object->head();
        $json = $this->vault->readObjectContent($objectId, $version, self::RECORD_PATH);
        $digest = hash($object->inventory->digestAlgorithm->hashAlgorithm(), $json);
        if (! in_array(self::RECORD_PATH, $object->inventory->versions[$version]->state[$digest] ?? [], true)) {
            throw new UnexpectedValueException('Archived Item record differs from its OCFL inventory.');
        }
        $record = json_decode(
            $json,
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
            || ! is_array($record['pins'] ?? null)
        ) {
            throw new UnexpectedValueException('Unsupported or inconsistent archived Item record.');
        }
        if ($this->objectIdForUri($record['uri'])->toString() !== $objectId->toString()) {
            throw new UnexpectedValueException('Archived Item URI does not match its Object identity.');
        }

        try {
            $pins = $this->pinReferences($record['pins']);
        } catch (InvalidItemRevision $exception) {
            throw new UnexpectedValueException('Unsupported archived Item pins.', previous: $exception);
        }

        return [
            'format' => self::FORMAT,
            'version' => 1,
            'identity_policy' => self::IDENTITY_POLICY,
            'object_id' => $objectId->toString(),
            'uri' => $record['uri'],
            'metadata' => $record['metadata'],
            'pins' => $pins,
        ];
    }

    public function objectIdForUri(string $uri): Urn
    {
        return $this->identity->objectIdForUri($uri);
    }

    /**
     * @param array<array-key, mixed> $pins
     * @return list<array{object_id: string, version: string}>
     */
    private function pinReferences(array $pins): array
    {
        if (! array_is_list($pins)) {
            throw new InvalidItemRevision('Asset pins must be a list of exact Object/version references.');
        }
        $references = [];
        $seen = [];
        foreach ($pins as $pin) {
            if (
                ! is_array($pin)
                || count($pin) !== 2
                || ! is_string($pin['object_id'] ?? null)
                || ! is_string($pin['version'] ?? null)
                || ! preg_match('/^urn:stashd:asset:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $pin['object_id'])
                || ! Uuid::isValid(substr($pin['object_id'], strlen('urn:stashd:asset:')))
                || ! preg_match('/^v[1-9][0-9]*$/D', $pin['version'])
            ) {
                throw new InvalidItemRevision('Each pin requires a canonical Asset Object ID and an exact OCFL version.');
            }
            $key = $pin['object_id'];
            if (isset($seen[$key])) {
                throw new InvalidItemRevision('Each Asset Object may be pinned only once per Item revision.');
            }
            $seen[$key] = true;
            $references[] = ['object_id' => $pin['object_id'], 'version' => $pin['version']];
        }

        return $references;
    }

    /** @param array<array-key, mixed> $metadata */
    private function validateMetadata(array $metadata): void
    {
        foreach ($metadata as $value) {
            if (is_array($value)) {
                $this->validateMetadata($value);
            } elseif (! is_null($value) && ! is_scalar($value)) {
                throw new InvalidItemRevision('Item metadata must contain JSON values, not PHP objects.');
            }
        }
    }
}
