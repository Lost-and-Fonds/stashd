<?php

namespace App\RecordsAndArchives;

use JsonException;
use League\Uri\Urn;
use Ottosmops\Ocfl\OcflObject;
use Ottosmops\Ocfl\Validation\OcflException;
use OutOfBoundsException;
use Symfony\Component\Uid\Uuid;
use UnexpectedValueException;

/**
 * Publish immutable Item metadata revisions with exact Asset version references.
 *
 * Item identity follows the shared exact-URI policy. Asset ownership and byte
 * evidence are verified before publication; Item and Asset commits remain
 * separate operations, with no transaction spanning their Objects.
 */
final readonly class ItemArchive
{
    private const string FORMAT = 'stashd-item';
    private const string IDENTITY_POLICY = 'exact-uri-v1';
    private const string RECORD_PATH = 'item.json';

    /**
     * Bind publication, deterministic identity and Asset verification services.
     */
    public function __construct(
        private Vault $vault,
        private ItemIdentity $identity,
        private AssetArchive $assets,
    ) {}

    /**
     * Append a complete Item metadata and pin snapshot for an exact canonical URI.
     *
     * Validates JSON values and verifies each unique Asset pin before writing.
     * Omitted metadata and pins become empty snapshots. Equal input still creates
     * a new revision. Concurrent first creators can race; the Vault rejects the
     * loser under its lock, and the caller may retry the whole operation.
     *
     * Asset checks precede the Item write lock and do not form a transaction
     * with Asset publication. A rejected Item revision does not remove Assets.
     *
     * @param array<array-key, mixed> $metadata Opaque JSON values, owned by the producer.
     * @param array<array-key, mixed> $pins Exact Asset Object/version references, verified before publication.
     * @param null|array{id: string, candidate: string, plan: string} $transaction Core transaction binding retained in this exact version.
     * @throws InvalidItemRevision When metadata or pins fail validation.
     * @throws UnexpectedValueException When existing Item identity is incompatible.
     */
    public function commitRevision(string $uri, array $metadata = [], array $pins = [], ?array $transaction = null): OcflObject
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
                ...($transaction === null ? [] : ['transaction' => $transaction]),
            ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $exception) {
            throw new InvalidItemRevision('Item metadata must contain valid JSON values.', previous: $exception);
        }

        $this->validateMetadata($metadata);
        $files = [self::RECORD_PATH => ['contents' => $json]];
        $root = $this->vault->open();

        if (! $root->hasObject($id->toString()) || $this->vault->getObject($id)->head() === '') {
            // shortcut: concurrent first creators may race here; Vault rejects the loser under its write lock. Retry the commitRevision call.
            return $this->vault->createObject(IdentifierType::Item, $uuid, $files);
        }

        // Refuse to append to an unrelated or incompatible Object at this identity.
        if ($this->read($id)['uri'] !== $uri) {
            throw new UnexpectedValueException('Item identity is already bound to a different canonical URI.');
        }

        return $this->vault->updateObject($id, $files);
    }

    /**
     * Read an exact Item revision, defaulting to the acknowledged head.
     *
     * Verifies record bytes against OCFL state and checks identity and pin
     * structure. It does not reverify the referenced Asset payloads. An explicit
     * missing version fails instead of falling back to the head.
     *
     * @return array{format: string, version: int, identity_policy: string, object_id: string, uri: string, metadata: array, pins: list<array{object_id: string, version: string}>}
     */
    public function read(Urn $objectId, ?string $version = null): array
    {
        $object = $this->vault->getObject($objectId);
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

    /**
     * Resolve the shared deterministic Item identity without reading or writing history.
     */
    public function objectIdForUri(string $uri): Urn
    {
        return $this->identity->objectIdForUri($uri);
    }

    /**
     * Validate and retain one exact version reference per canonical Asset ID.
     *
     * Rejects aliases, extra fields and duplicate Object IDs with
     * InvalidItemRevision. Existence and ownership are checked separately.
     *
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

    /**
     * Recursively reject PHP objects and other non-JSON metadata values.
     *
     * Scalar encoding errors are checked by JSON serialization before this
     * helper runs. Invalid values raise InvalidItemRevision.
     *
     * @param array<array-key, mixed> $metadata
     */
    public function validateMetadata(array $metadata): void
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
