<?php

declare(strict_types=1);

namespace App\Vault;

/**
 * "Explain Generated Files" (spec Sec30): whether an asset is an original
 * fetched from the provider or something Stashd produced, and what that
 * implies about regenerating or deleting it.
 */
final readonly class AssetRegenerationGuidance
{
    private function __construct(
        public ?string $generatedBy,
        public bool $canRegenerate,
        public bool $safeToDelete,
    ) {}

    /**
     * An asset is "generated" if it's tied to a broadcast or explicitly
     * derived from another asset — both are existing relational columns on
     * AssetRecord, so this holds for any future role without per-role rules.
     * Everything else (vault_original, source_thumbnail, source/metadata
     * json fetched straight from the provider) is a source asset: the only
     * way to "regenerate" it is re-fetching upstream, and deleting it isn't
     * safe since nothing else can reproduce it.
     */
    public static function forAsset(
        AssetRecord $asset,
        ?string $broadcastName,
        bool $vaultOriginalReady,
        UpstreamState $mediaItemUpstreamState,
    ): self {
        $isGenerated = $asset->broadcastId !== null || $asset->derivedFromAssetId !== null;

        if (! $isGenerated) {
            return new self(
                generatedBy: null,
                canRegenerate: $mediaItemUpstreamState === UpstreamState::Available,
                safeToDelete: false,
            );
        }

        return new self(
            generatedBy: $broadcastName,
            canRegenerate: $vaultOriginalReady,
            safeToDelete: true,
        );
    }
}
