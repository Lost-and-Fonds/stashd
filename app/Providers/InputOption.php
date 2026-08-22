<?php

declare(strict_types=1);

namespace App\Providers;

/**
 * Generic metadata for a provider-declared per-input option. Surfaced through
 * preflight so UI code can render it without knowing provider option keys.
 */
final readonly class InputOption
{
    /**
     * @param  list<string>|null  $choices  required and meaningful only for `InputOptionType::Enum`
     * @param  list<string>  $applicableInputTypes  plugin-defined input-kind strings
     * @param  list<string>  $excludesContentTypes  `DiscoveredItem::$contentType` values this option
     *                                              excludes when set to `false` (bool options only). This lets the
     *                                              generic commit-time filter remain provider-agnostic.
     */
    public function __construct(
        public string $key,
        public string $label,
        public InputOptionType $type,
        public bool|string $default,
        public ?array $choices = null,
        public array $applicableInputTypes = [],
        public array $excludesContentTypes = [],
        public ?string $description = null,
        public bool $required = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type->value,
            'default' => $this->default,
            'choices' => $this->choices,
            'applicable_input_types' => $this->applicableInputTypes,
            'description' => $this->description,
            'required' => $this->required,
        ];
    }
}
