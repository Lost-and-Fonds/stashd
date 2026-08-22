<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Config\StashdConfig;
use App\Vault\AssetKind;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use App\Vault\AssetState;
use App\Vault\MediaItemId;

test('derived asset identity keeps different opaque derivations separate', function (): void {
    [, , $mediaItemId] = $this->bootstrapFakeDownloadStash('derived-asset-identity');
    $config = $this->container->get(StashdConfig::class);
    $assets = $this->container->get(AssetRepository::class);
    $root = $config->vaultPath() . '/derived-identity-tests';
    mkdir($root, 0775, true);

    foreach (['mp3-128-v1' => 'audio-a', 'opus-64-v1' => 'audio-b'] as $key => $bytes) {
        $path = $root . '/' . $key . '.audio';
        file_put_contents($path, $bytes);
        $assets->create(
            mediaItemId: MediaItemId::parse($mediaItemId),
            role: AssetRole::Derived,
            kind: AssetKind::Audio,
            state: AssetState::Ready,
            path: $path,
            relativePath: 'derived-identity-tests/' . $key . '.audio',
            mimeType: 'audio/mpeg',
            sizeBytes: strlen($bytes),
            checksum: hash('sha256', $bytes),
            derivationKey: $key,
        );
    }

    $derived = array_values(array_filter(
        $assets->listForMediaItem(MediaItemId::parse($mediaItemId)),
        static fn($asset): bool => $asset->role === AssetRole::Derived,
    ));

    expect($derived)->toHaveCount(2)
        ->and(array_map(static fn($asset): ?string => $asset->derivationKey, $derived))
        ->toEqualCanonicalizing(['mp3-128-v1', 'opus-64-v1']);
});
