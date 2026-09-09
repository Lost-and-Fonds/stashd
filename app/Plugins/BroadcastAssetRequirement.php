<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Vault\AssetKind;
use App\Vault\AssetRecord;
use App\Vault\AssetRole;

final readonly class BroadcastAssetRequirement
{
    public function __construct(
        public string $role,
        public AssetRole $assetRole,
        public ?AssetKind $kind,
        public bool $required,
        private ?string $setting = null,
        private mixed $conditionValue = null,
        private bool $negated = false,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromManifest(array $raw): ?self
    {
        $role = is_string($raw['role'] ?? null) ? $raw['role'] : '';
        $assetRole = match ($role) {
            'primary' => AssetRole::VaultOriginal,
            'captions' => AssetRole::Subtitle,
            'artwork' => AssetRole::SourceThumbnail,
            'metadata' => AssetRole::MetadataJson,
            default => null,
        };
        $kind = ($raw['kind'] ?? null) === null
            ? null
            : (is_string($raw['kind']) ? AssetKind::tryFrom($raw['kind']) : null);

        if ($assetRole === null || ($raw['kind'] ?? null) !== null && $kind === null || ! is_bool($raw['required'] ?? null)) {
            return null;
        }

        $when = $raw['when'] ?? null;

        if ($when === null) {
            return new self($role, $assetRole, $kind, $raw['required']);
        }

        if (! is_array($when) || ! is_string($when['setting'] ?? null) || trim($when['setting']) === '') {
            return null;
        }

        $hasEquals = array_key_exists('equals', $when);
        $hasNotEquals = array_key_exists('not_equals', $when);

        if ($hasEquals === $hasNotEquals) {
            return null;
        }

        $conditionValue = $hasEquals ? $when['equals'] : $when['not_equals'];

        if (! is_bool($conditionValue) && ! is_int($conditionValue) && ! is_float($conditionValue) && ! is_string($conditionValue)) {
            return null;
        }

        return new self(
            role: $role,
            assetRole: $assetRole,
            kind: $kind,
            required: $raw['required'],
            setting: trim($when['setting']),
            conditionValue: $conditionValue,
            negated: $hasNotEquals,
        );
    }

    /** @param array<string, mixed> $settings */
    public function enabled(array $settings): bool
    {
        if ($this->setting === null) {
            return true;
        }

        $matches = ($settings[$this->setting] ?? null) === $this->conditionValue;

        return $this->negated ? ! $matches : $matches;
    }

    public function matches(AssetRecord $asset): bool
    {
        return $asset->role === $this->assetRole
            && ($this->kind === null || $asset->kind === $this->kind);
    }
}
