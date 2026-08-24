<?php

declare(strict_types=1);

namespace App\Plugins;

use InvalidArgumentException;

/** Plugin-declared source-identification field; not an Input option. */
final readonly class PluginSourceField
{
    /** @param list<string>|null $choices */
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public bool $required = false,
        public ?array $choices = null,
        public ?string $description = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'required' => $this->required,
            'choices' => $this->choices,
            'description' => $this->description,
        ];
    }

    public function normalize(mixed $value): bool|int|string
    {
        $valid = match ($this->type) {
            'bool' => is_bool($value),
            'number' => is_int($value),
            'text', 'enum' => is_string($value),
            default => false,
        };

        if (! $valid || $this->type === 'enum' && ! in_array($value, $this->choices ?? [], true)) {
            throw new InvalidArgumentException("Invalid value for source field {$this->key}.");
        }

        if (is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException("Invalid value for source field {$this->key}.");
    }
}
