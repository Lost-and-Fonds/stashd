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
    /**
     * Retain a canonical namespace and UUID without requiring a PHP model for that namespace.
     */
    public function __construct(
        public string $namespace,
        public Uuid $uuid,
    ) {
        if (! preg_match('/\A[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*\z/D', $namespace)) {
            throw new InvalidArgumentException('Expected a lowercase identity namespace with single hyphen or underscore separators.');
        }
    }

    /**
     * Generate a UUIDv7 in the concrete model's public namespace.
     *
     * @param class-string $model
     */
    public static function create(string $model): self
    {
        return new self(Identity::forModel($model), Uuid::v7());
    }

    /**
     * Reconstitute a model identity without changing its stored UUID.
     *
     * @param class-string $model
     */
    public static function fromUuid(string $model, string $uuid): self
    {
        return new self(Identity::forModel($model), Uuid::fromString($uuid));
    }

    /**
     * Parse a public URN without reverse model lookup, optionally checking an expected model.
     *
     * @param class-string|null $expected
     */
    public static function parse(string $urn, ?string $expected = null): self
    {
        $parts = [];
        if (! preg_match('/\Aurn:stashd:([^:]+):([0-9a-fA-F-]{36})\z/D', $urn, $parts)) {
            throw new InvalidArgumentException('Expected a typed Stashd URN.');
        }

        $id = new self($parts[1], Uuid::fromString($parts[2]));
        if ($expected !== null) {
            $id->uuidFor($expected);
        }

        return $id;
    }

    /**
     * Return the UUID only when it belongs to the expected model's namespace.
     *
     * @param class-string $model
     */
    public function uuidFor(string $model): string
    {
        $expected = Identity::forModel($model);
        if ($this->namespace !== $expected) {
            throw new InvalidArgumentException('Expected a ' . $expected . ' identifier, received ' . $this->namespace . '.');
        }

        return $this->uuid->toRfc4122();
    }

    /**
     * Render the canonical public URN, including the retained namespace.
     */
    public function __toString(): string
    {
        return (string) Urn::fromRfc2141(nid: 'stashd', nss: $this->namespace . ':' . $this->uuid->toRfc4122());
    }

    /**
     * Serialize identities as URN strings rather than PHP implementation details.
     */
    public function jsonSerialize(): string
    {
        return (string) $this;
    }
}
