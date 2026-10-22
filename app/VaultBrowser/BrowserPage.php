<?php

namespace App\VaultBrowser;

/**
 * Carry the route-specific data supplied to the read-only browser template.
 *
 * The kind distinguishes the controller's overview, Item, Asset and error shapes.
 *
 * @phpstan-type BrowserItem array{object_id: string, uri: string, metadata: array, ...}
 * @phpstan-type BrowserAsset array{object_id: string, item_object_id: string, item_uri: string, asset_key: string, files: array<string, array{size: int, algorithm: string, digest: string}>, ...}
 * @phpstan-type VaultPage array{kind: 'Vault', state: string, identity?: ?string, items?: list<array{object_id: string, uri: string, metadata: array, ocfl_version: string, ...}>, notices: list<string>}
 * @phpstan-type ItemPage array{kind: 'Item', record: BrowserItem, selected: string, versions: array<string, \Ottosmops\Ocfl\Inventory\Version>, pins: list<array{object_id: string, version: string, asset: BrowserAsset}|array{object_id: string, version: string, error: string}>}
 * @phpstan-type AssetPage array{kind: 'Asset', record: BrowserAsset, selected: string}
 * @phpstan-type BrowserData VaultPage|ItemPage|AssetPage|array{kind: 'Unavailable', error: string}
 */
final readonly class BrowserPage
{
    /**
     * Retain the controller's verified records and display data without rereading storage.
     *
     * @param BrowserData $data
     */
    public function __construct(
        public array $data,
    ) {}
}
