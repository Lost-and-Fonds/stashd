<?php

declare(strict_types=1);

namespace App\Identity;

use InvalidArgumentException;
use JsonSerializable;
use League\Uri\Urn;
use Stringable;
use Symfony\Component\Uid\Uuid;
use Tempest\Mapper\CastWith;
use Tempest\Mapper\SerializeWith;

/**
 * A namespace and UUID identify one domain object; neither proves existence or authority.
 */
#[CastWith(IdentifierMapper::class)]
#[SerializeWith(IdentifierMapper::class)]
final readonly class Identifier implements Stringable, JsonSerializable
{
    public function __construct(
        public IdentifierType $type,
        public Uuid $uuid,
    ) {}

    public static function create(IdentifierType $type): self
    {
        return new self($type, Uuid::v7());
    }

    public static function fromUuid(IdentifierType $type, string $uuid): self
    {
        return new self($type, Uuid::fromString($uuid));
    }

    public static function parse(string $urn, ?IdentifierType $expected = null): self
    {
        $parts = [];
        if (! preg_match('/\Aurn:stashd:([a-z]+):([0-9a-fA-F-]{36})\z/D', $urn, $parts)) {
            throw new InvalidArgumentException('Expected a typed Stashd URN.');
        }

        $type = IdentifierType::tryFrom($parts[1]) ?? throw new InvalidArgumentException('Unknown Stashd identifier type.');
        $id = self::fromUuid($type, $parts[2]);
        if ($expected !== null) {
            $id->uuidFor($expected);
        }

        return $id;
    }

    public function uuidFor(IdentifierType $expected): string
    {
        if ($this->type !== $expected) {
            throw new InvalidArgumentException('Expected a ' . $expected->value . ' identifier, received ' . $this->type->value . '.');
        }

        return $this->uuid->toRfc4122();
    }

    public function __toString(): string
    {
        return (string) Urn::fromRfc2141(nid: 'stashd', nss: $this->type->value . ':' . $this->uuid->toRfc4122());
    }

    public function jsonSerialize(): string
    {
        return (string) $this;
    }
}
