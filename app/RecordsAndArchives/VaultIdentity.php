<?php

namespace App\RecordsAndArchives;

use JsonSerializable;
use League\Uri\Urn;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\DateTimeInterface;
use Tempest\DateTime\FormatPattern;
use Tempest\Validation\Rules\HasDateTimeFormat;
use TypeError;

/**
 * Represent the identity persisted separately from the Vault OCFL root.
 *
 * Serialization retains format, schema version, permanent URN and creation
 * time. This data object does not enforce supported format or version values.
 */
class VaultIdentity implements JsonSerializable
{
    /**
     * Hold the supplied identity fields without reading storage or validating the schema.
     */
    public function __construct(
        public string $format,
        public int $version,
        public Urn $identifier,

        #[HasDateTimeFormat(FormatPattern::ISO8601)]
        public DateTimeInterface $createdAt,
    ) {}

    /**
     * Decode persisted identity fields and parse the URN and creation timestamp.
     *
     * Malformed JSON or incompatible field values propagate decoding, parsing or
     * type errors. Missing fields and incorrect JSON types raise TypeError before
     * parsing; format and version compatibility remain the caller's responsibility.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode(
            $json,
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (
            ! is_array($data)
            || ! is_string($data['format'] ?? null)
            || ! is_int($data['version'] ?? null)
            || ! is_string($data['identifier'] ?? null)
            || ! is_string($data['createdAt'] ?? null)
        ) {
            throw new TypeError('Vault identity must contain string format, identifier and createdAt fields and an integer version.');
        }

        return new self(
            format: $data['format'],
            version: $data['version'],
            identifier: Urn::fromString($data['identifier']),
            createdAt: DateTime::parse($data['createdAt']),
        );
    }

    /**
     * Emit identity fields with a string URN and ISO 8601 creation timestamp.
     */
    public function jsonSerialize(): array
    {
        return [
            'format' => $this->format,
            'version' => $this->version,
            'identifier' => $this->identifier->toString(),
            'createdAt' => $this->createdAt->format(FormatPattern::ISO8601),
        ];
    }
}
