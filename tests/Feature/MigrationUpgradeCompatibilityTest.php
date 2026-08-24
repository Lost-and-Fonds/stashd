<?php

declare(strict_types=1);

use Tempest\Database\Database;
use Tempest\Database\Migrations\Migration;
use Tempest\Database\Migrations\RunnableMigrations;

test('released domain state converges through current forward migrations', function (): void {
    $migrations = $this->container->get(RunnableMigrations::class);
    $names = array_map(static fn($migration): string => $migration->name, iterator_to_array($migrations->up()));

    expect($names)
        ->toContain('2026_06_17_create_domain_schema')
        ->toContain('2026_08_21_remove_broadcast_publication_token_columns')
        ->toContain('2026_08_22_add_asset_derivation_key')
        ->and(Migration::all())->toHaveCount(count($names));

    $database = $this->container->get(Database::class);
    $broadcastColumns = schemaColumns($database, 'broadcasts');
    $assetColumns = schemaColumns($database, 'assets');

    expect($broadcastColumns)
        ->not->toContain('tokenSecretId')
        ->not->toContain('tokenPreview')
        ->and($assetColumns)->toContain('derivationKey');
});
