<?php

declare(strict_types=1);

namespace App\Plugins;

use App\System\Secret\SecretType;

/** Plugin-declared credential, separate from its invocation grant. */
final readonly class PluginCredentialDefinition
{
    public function __construct(
        public string $key,
        public string $label,
        public string $secretKey,
        public SecretType $secretType = SecretType::Generic,
        public bool $required = false,
        public ?string $description = null,
        public string $inputType = 'password',
        public bool $rawAccess = false,
    ) {}

    /** @return array<string, bool|string|null> */
    public function toArray(bool $configured): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'required' => $this->required,
            'input_type' => $this->inputType,
            'configured' => $configured,
        ];
    }
}
