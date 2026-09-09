<?php

declare(strict_types=1);

use App\Plugins\BroadcastAssetRequirement;
use App\Plugins\ExternalBroadcastPluginDefinition;
use App\Vault\AssetKind;
use App\Vault\AssetRole;

it('parses conditional broadcast asset requirements', function (): void {
    $requirement = BroadcastAssetRequirement::fromManifest([
        'role' => 'captions',
        'kind' => 'subtitle',
        'required' => false,
        'when' => ['setting' => 'captions', 'not_equals' => 'off'],
    ]);

    expect($requirement)->not->toBeNull()
        ->and($requirement->assetRole)->toBe(AssetRole::Subtitle)
        ->and($requirement->kind)->toBe(AssetKind::Subtitle)
        ->and($requirement->enabled(['captions' => 'creator_only']))->toBeTrue()
        ->and($requirement->enabled(['captions' => 'off']))->toBeFalse();
});

it('rejects ambiguous or malformed conditions', function (): void {
    expect(BroadcastAssetRequirement::fromManifest([
        'role' => 'primary',
        'required' => true,
        'when' => ['setting' => 'mode', 'equals' => 'audio', 'not_equals' => 'video'],
    ]))->toBeNull()
        ->and(BroadcastAssetRequirement::fromManifest([
            'role' => 'primary',
            'required' => true,
            'when' => ['setting' => 'mode', 'equals' => ['audio']],
        ]))->toBeNull();
});

it('loads requirements from a broadcast manifest', function (): void {
    $definition = ExternalBroadcastPluginDefinition::fromManifest([
        'id' => 'podcast',
        'broadcast_key' => 'podcast',
        'asset_requirements' => [
            ['role' => 'primary', 'required' => true],
            ['role' => 'captions', 'kind' => 'subtitle', 'required' => false],
        ],
    ], sys_get_temp_dir(), '/tmp/plugin.sock');

    expect($definition)->toBeInstanceOf(ExternalBroadcastPluginDefinition::class);

    expect($definition->assetRequirements)->toHaveCount(2)
        ->and($definition->assetRequirements[0]->required)->toBeTrue()
        ->and($definition->assetRequirements[1]->kind)->toBe(AssetKind::Subtitle);
});
