<?php

namespace App\RecordsAndArchives;

use JsonSerializable;
use League\Uri\Urn;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\DateTimeInterface;
use Tempest\DateTime\FormatPattern;
use Tempest\Validation\Rules\HasDateTimeFormat;

class VaultIdentity implements JsonSerializable
{
    public function __construct(
        public string $format,
        public int $version,
        public Urn $identifier,

        #[HasDateTimeFormat(FormatPattern::ISO8601)]
        public DateTimeInterface $createdAt,
    ) {}

    public static function fromJson(string $json): self
    {
        $data = json_decode(
            $json,
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        return new self(
            format: $data['format'],
            version: $data['version'],
            identifier: Urn::fromString($data['identifier']),
            createdAt: DateTime::parse($data['createdAt']),
        );
    }

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
