<?php

declare(strict_types=1);

namespace App\Providers;

use InvalidArgumentException;
use Stringable;
use Tempest\Support\Uri\Uri;

use function Tempest\Support\str;

/** Stashd-owned URI value object wrapping Tempest's {@see Uri}. */
final readonly class StashdUri implements Stringable
{
    /** @var list<string> */
    private const array SUPPORTED_SCHEMES = ['http', 'https', 'fake'];

    public function __construct(
        public Uri $uri,
    ) {
        $scheme = str($this->uri->scheme ?? '')->lower()->toString();

        if (! in_array($scheme, self::SUPPORTED_SCHEMES, true)) {
            throw new InvalidArgumentException("Unsupported URI scheme: {$scheme}");
        }
    }

    public static function parse(string $value): self
    {
        $trimmed = str($value)->trim();

        if ($trimmed->isEmpty()) {
            throw new InvalidArgumentException('URI must not be empty.');
        }

        return new self(Uri::from($trimmed->toString()));
    }

    public static function fake(string $path): self
    {
        return self::parse('fake://' . str($path)->ltrim('/')->toString());
    }

    public function toString(): string
    {
        return $this->uri->toString();
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function scheme(): string
    {
        return str($this->uri->scheme ?? '')->lower()->toString();
    }

    public function host(): string
    {
        return str($this->uri->host ?? '')->lower()->toString();
    }

    public function path(): string
    {
        return $this->uri->path ?? '/';
    }

    /** @return list<string> */
    public function segments(): array
    {
        /** @var list<string> $segments */
        $segments = $this->uri->segments;

        return $segments;
    }

    /** @return array<string, mixed> */
    public function query(): array
    {
        /** @var array<string, mixed> $query */
        $query = $this->uri->query;

        return $query;
    }

    public function queryParam(string $key, mixed $default = null): mixed
    {
        return $this->uri->query[$key] ?? $default;
    }

    public function pathStartsWith(string $prefix): bool
    {
        return str($this->path())->startsWith($prefix);
    }

    public function withQuery(mixed ...$query): self
    {
        return new self($this->uri->withQuery(...$query));
    }
}
