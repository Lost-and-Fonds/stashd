<?php

declare(strict_types=1);

use App\Database\NormalizeLegacyAssetRoles;
use App\Database\SupportedPostgresBaseline;
use App\Support\PrefixedUlidGenerator;
use App\System\Boot\LegacyBaselineAdopter;
use App\System\Boot\MigrationRunner;
use App\Vault\AssetId;
use App\Vault\AssetRepository;
use App\Vault\AssetRole;
use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Database;
use Tempest\Database\Migrations\Migration;
use Tempest\Database\Migrations\RunnableMigrations;
use Tempest\Database\Query;

test('fresh databases converge from the supported baseline', function (): void {
    $migrations = $this->container->get(RunnableMigrations::class);
    $names = array_map(static fn($migration): string => $migration->name, iterator_to_array($migrations->up()));

    expect($names)
        ->toContain(SupportedPostgresBaseline::NAME)
        ->toContain('2026_08_21_remove_broadcast_publication_token_columns')
        ->toContain('2026_08_22_add_asset_derivation_key')
        ->toContain('2026_08_23_normalize_legacy_asset_roles')
        ->toContain('2026_09_07_create_preservation_events')
        ->not->toContain('2026_06_17_create_domain_schema')
        ->and(Migration::all())->toHaveCount(count($names));

    $database = $this->container->get(Database::class);
    $broadcastColumns = schemaColumns($database, 'broadcasts');
    $assetColumns = schemaColumns($database, 'assets');

    expect($broadcastColumns)
        ->not->toContain('tokenSecretId')
        ->not->toContain('tokenPreview')
        ->and($assetColumns)->toContain('derivationKey');

    expect(schemaTableExists($database, 'preservation_events'))->toBeTrue();
});

test('the historical podcast audio role is normalized to a derived asset', function (): void {
    [, , $itemId] = $this->bootstrapFakeDownloadStash('legacy-podcast-audio');
    $database = $this->container->get(Database::class);
    $assetId = $this->container->get(PrefixedUlidGenerator::class)->generate('asset')->toString();

    $database->execute(new Query(
        'INSERT INTO assets (id, role, kind, state, "itemId") VALUES (?, ?, ?, ?, ?)',
        bindings: [$assetId, 'podcast_audio', 'audio', 'ready', $itemId],
    ));

    $database->execute(new Query(
        (new NormalizeLegacyAssetRoles())->up()->compile(DatabaseDialect::POSTGRESQL),
    ));

    $asset = $this->container->get(AssetRepository::class)->find(AssetId::parse($assetId));

    expect($asset?->role)->toBe(AssetRole::Derived);
});

test('an unknown asset role still fails instead of being coerced', function (): void {
    [, , $itemId] = $this->bootstrapFakeDownloadStash('unknown-asset-role');
    $database = $this->container->get(Database::class);
    $assetId = $this->container->get(PrefixedUlidGenerator::class)->generate('asset')->toString();

    $database->execute(new Query(
        'INSERT INTO assets (id, role, kind, state, "itemId") VALUES (?, ?, ?, ?, ?)',
        bindings: [$assetId, 'not_a_real_role', 'audio', 'ready', $itemId],
    ));

    expect(fn() => $this->container->get(AssetRepository::class)->find(AssetId::parse($assetId)))
        ->toThrow(ValueError::class);
});

test('known legacy history is adopted before post-baseline migrations run', function (): void {
    $database = $this->container->get(Database::class);
    prepareLegacyBaseline($database);

    $this->container->get(LegacyBaselineAdopter::class)->adopt();

    expect(array_map(static fn(Migration $migration): string => $migration->name, Migration::all()))
        ->toContain(SupportedPostgresBaseline::NAME);

    $this->container->get(MigrationRunner::class)->run();

    $names = array_map(static fn(Migration $migration): string => $migration->name, Migration::all());

    expect($names)
        ->toContain(SupportedPostgresBaseline::NAME)
        ->toContain('2026_08_22_add_asset_derivation_key')
        ->toContain('2026_09_08_rename_media_item_to_item')
        ->not->toContain('2026_07_15_drop_stash_slug')
        ->and(schemaColumns($database, 'broadcasts'))->not->toContain('tokenSecretId')
        ->and(schemaTableExists($database, 'items'))->toBeTrue();
});

test('unknown legacy history is refused instead of adopted', function (): void {
    $database = $this->container->get(Database::class);

    replaceMigrationHistory($database);

    expect(fn() => $this->container->get(LegacyBaselineAdopter::class)->adopt())
        ->toThrow(\RuntimeException::class, 'does not match the expected baseline schema');
});

function prepareLegacyBaseline(Database $database): void
{
    $database->execute(new Query('ALTER TABLE activity_events RENAME COLUMN "itemId" TO "mediaItemId"'));
    $database->execute(new Query('ALTER TABLE media_timeline_entries RENAME CONSTRAINT "media_timeline_entries_itemId_fkey" TO "media_timeline_entries_mediaItemId_fkey"'));
    $database->execute(new Query('ALTER TABLE stash_items RENAME CONSTRAINT "stash_items_itemId_fkey" TO "stash_items_mediaItemId_fkey"'));
    $database->execute(new Query('ALTER TABLE item_sources RENAME CONSTRAINT "item_sources_itemId_fkey" TO "media_item_sources_mediaItemId_fkey"'));
    $database->execute(new Query('ALTER TABLE broadcast_items RENAME CONSTRAINT "broadcast_items_itemId_fkey" TO "broadcast_items_mediaItemId_fkey"'));
    $database->execute(new Query('ALTER TABLE assets RENAME CONSTRAINT "assets_itemId_fkey" TO "assets_mediaItemId_fkey"'));
    $database->execute(new Query('ALTER TABLE media_timeline_entries RENAME COLUMN "itemId" TO "mediaItemId"'));
    $database->execute(new Query('ALTER TABLE stash_items RENAME COLUMN "itemId" TO "mediaItemId"'));
    $database->execute(new Query('ALTER TABLE item_sources RENAME COLUMN "itemId" TO "mediaItemId"'));
    $database->execute(new Query('ALTER TABLE broadcast_items RENAME COLUMN "itemId" TO "mediaItemId"'));
    $database->execute(new Query('ALTER TABLE assets RENAME COLUMN "itemId" TO "mediaItemId"'));
    $database->execute(new Query('ALTER INDEX assets_item_id RENAME TO assets_media_item_id'));
    $database->execute(new Query('ALTER INDEX broadcast_items_item_id RENAME TO broadcast_items_media_item_id'));
    $database->execute(new Query('ALTER INDEX item_sources_item_id RENAME TO media_item_sources_media_item_id'));
    $database->execute(new Query('ALTER INDEX items_provider_key RENAME TO media_items_provider_key'));
    $database->execute(new Query('ALTER INDEX items_provider_key_provider_item_id RENAME TO media_items_provider_key_provider_item_id'));
    $database->execute(new Query('ALTER INDEX items_state RENAME TO media_items_state'));
    $database->execute(new Query('ALTER INDEX media_timeline_entries_item_id RENAME TO media_timeline_entries_media_item_id'));
    $database->execute(new Query('ALTER INDEX media_timeline_entries_item_id_source_external_id RENAME TO media_timeline_entries_media_item_id_source_external_id'));
    $database->execute(new Query('ALTER INDEX stash_items_item_id RENAME TO stash_items_media_item_id'));
    $database->execute(new Query('ALTER INDEX stash_items_stash_id_item_id RENAME TO stash_items_stash_id_media_item_id'));
    $database->execute(new Query('ALTER TABLE item_sources RENAME TO media_item_sources'));
    $database->execute(new Query('ALTER TABLE items RENAME TO media_items'));
    $database->execute(new Query('DROP TABLE IF EXISTS preservation_events'));
    $database->execute(new Query('DROP TABLE published_resources'));
    $database->execute(new Query('DROP INDEX assets_derived_identity'));
    $database->execute(new Query('ALTER TABLE media_items DROP COLUMN IF EXISTS "sizeEstimated"'));
    $database->execute(new Query('ALTER TABLE media_items DROP COLUMN IF EXISTS "sizeBytes"'));
    $database->execute(new Query('ALTER TABLE assets DROP COLUMN "derivationKey"'));
    $database->execute(new Query('ALTER TABLE jobs ADD COLUMN "priority" INTEGER DEFAULT 100 NOT NULL'));
    $database->execute(new Query('CREATE INDEX jobs_pending_claim ON jobs (state, "priority", "createdAt")'));
    $database->execute(new Query('ALTER TABLE broadcasts ADD COLUMN "tokenSecretId" VARCHAR(40) NULL'));
    $database->execute(new Query('ALTER TABLE broadcasts ADD COLUMN "tokenPreview" VARCHAR(255) NULL'));
    $database->execute(new Query('ALTER TABLE broadcasts ADD CONSTRAINT "broadcasts_tokenSecretId_fkey" FOREIGN KEY ("tokenSecretId") REFERENCES secrets(id) ON DELETE SET NULL'));
    $database->execute(new Query('ALTER TABLE broadcast_items ADD COLUMN "tokenSecretId" VARCHAR(40) NULL'));
    $database->execute(new Query('ALTER TABLE broadcast_items ADD COLUMN "tokenPreview" VARCHAR(255) NULL'));
    $database->execute(new Query('CREATE TABLE broadcast_sponsorblock_refreshes (id VARCHAR(40) NOT NULL PRIMARY KEY)'));

    replaceMigrationHistory($database);
}

function replaceMigrationHistory(Database $database): void
{
    $database->execute(new Query('DELETE FROM migrations'));

    foreach (LegacyBaselineAdopter::LEGACY_MIGRATIONS as $name => $hash) {
        $database->execute(new Query(
            'INSERT INTO migrations (name, hash) VALUES (?, ?)',
            bindings: [$name, $hash],
        ));
    }
}
