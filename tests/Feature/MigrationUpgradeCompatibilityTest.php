<?php

declare(strict_types=1);

use App\Database\SupportedPostgresBaseline;
use App\System\Boot\LegacyBaselineAdopter;
use App\System\Boot\MigrationRunner;
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
        ->not->toContain('2026_06_17_create_domain_schema')
        ->and(Migration::all())->toHaveCount(count($names));

    $database = $this->container->get(Database::class);
    $broadcastColumns = schemaColumns($database, 'broadcasts');
    $assetColumns = schemaColumns($database, 'assets');

    expect($broadcastColumns)
        ->not->toContain('tokenSecretId')
        ->not->toContain('tokenPreview')
        ->and($assetColumns)->toContain('derivationKey');
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
        ->not->toContain('2026_07_15_drop_stash_slug')
        ->and(schemaColumns($database, 'broadcasts'))->not->toContain('tokenSecretId');
});

test('unknown legacy history is refused instead of adopted', function (): void {
    $database = $this->container->get(Database::class);

    replaceMigrationHistory($database);

    expect(fn() => $this->container->get(LegacyBaselineAdopter::class)->adopt())
        ->toThrow(\RuntimeException::class, 'does not match the expected baseline schema');
});

function prepareLegacyBaseline(Database $database): void
{
    $database->execute(new Query('DROP TABLE published_resources'));
    $database->execute(new Query('DROP INDEX assets_derived_identity'));
    $database->execute(new Query('ALTER TABLE assets DROP COLUMN "derivationKey"'));
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
