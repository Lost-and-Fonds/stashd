<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Providers\InputOption;
use App\Stashes\StashInputOptions;
use App\Vault\AssetKind;
use App\Vault\AssetRole;

final readonly class PluginAssetCapability
{
    public function __construct(
        public string $role,
        public AssetRole $assetRole,
        public AssetKind $kind,
        public ?string $option = null,
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
        $kind = is_string($raw['kind'] ?? null) ? AssetKind::tryFrom($raw['kind']) : null;
        $option = $raw['option'] ?? null;

        if ($assetRole === null || $kind === null || $option !== null && ! is_string($option)) {
            return null;
        }

        return new self($role, $assetRole, $kind, $option);
    }

    public function enabled(?StashInputOptions $options, array $declaredOptions): bool
    {
        if ($this->option === null) {
            return true;
        }

        foreach ($declaredOptions as $declared) {
            if ($declared instanceof InputOption && $declared->key === $this->option) {
                return ($options === null ? $declared->default : $options->providerValue($declared)) === true;
            }
        }

        return false;
    }
}
