<?php

declare(strict_types=1);

namespace App\Broadcasts;

/**
 * Describes a UI control that a broadcast plugin exposes for configuration.
 */
final readonly class UiControl
{
    public function __construct(
        /** Control name (unique within the plugin). */
        public string $name,
        /** Human-readable label for the control. */
        public string $label,
        /** Control type hint (e.g. "text", "select", "toggle"). */
        public string $type = 'text',
        /** Default value for the control. */
        public mixed $default = null,
        /** Options for select-type controls. */
        public array $options = [],
        public ?string $description = null,
        public bool $required = false,
    ) {}
}
