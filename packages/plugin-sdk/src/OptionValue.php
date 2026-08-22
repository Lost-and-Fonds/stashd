<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final class OptionValue
{
    private function __construct(public readonly string $kind, public readonly bool|int|string $value) {}

    public static function boolean(bool $value): self
    {
        return new self('boolean', $value);
    }

    public static function number(int $value): self
    {
        return new self('number', $value);
    }

    public static function text(string $value): self
    {
        return new self('text', $value);
    }

    /** @param array{tag?: mixed, value?: mixed} $value */
    public static function fromWire(array $value): self
    {
        $tag = $value['tag'] ?? null;
        $raw = $value['value'] ?? null;
        return match ($tag) {
            'boolean' => self::boolean((bool) $raw),
            'number' => self::number((int) $raw),
            'text' => self::text((string) $raw),
            default => throw new \InvalidArgumentException('unknown option value type'),
        };
    }

    /** @return array{tag: string, value: bool|int|string} */
    public function toWire(): array
    {
        return ['tag' => $this->kind, 'value' => $this->value];
    }
}
