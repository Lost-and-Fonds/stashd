<?php

use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\RecordsAndArchives\ProjectionProbe;
use Tests\Support\RecordsAndArchives\TransactionProbe;

// Explicit local witness proof; excluded from the normal testsuite directories.
test('the PostgreSQL witness rejects response only rebuilds and incorrect persisted relationships', function (): void {
    $config = TransactionProbe::json(dirname(__DIR__, 3) . '/tmp/projection-proof.json');
    $admin = new PDO($config['dsn'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $name = 'stashd_tx_test_' . bin2hex(random_bytes(8));
    $password = bin2hex(random_bytes(24));
    $databaseCreated = false;
    $admin->exec('CREATE ROLE "' . $name . '" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD ' . $admin->quote($password));
    try {
        $admin->exec('CREATE DATABASE "' . $name . '" OWNER "' . $name . '"');
        $databaseCreated = true;
        $projection = [
            'dsn' => 'pgsql:host=lerd-postgres;port=5432;dbname=' . $name,
            'username' => $name,
            'password' => $password,
            'schema' => $name,
            'tables' => [
                'items' => ['name' => 'items', 'columns' => ['object_id' => 'object_id', 'version' => 'version']],
                'pins' => [
                    'name' => 'pins',
                    'columns' => [
                        'item_object_id' => 'item_object_id',
                        'item_version' => 'item_version',
                        'asset_object_id' => 'asset_object_id',
                        'asset_version' => 'asset_version',
                    ],
                ],
            ],
        ];
        $database = ProjectionProbe::connect($projection, $name);
        $schema = '"' . $name . '"';
        $database->exec('CREATE SCHEMA ' . $schema);
        $database->exec('CREATE TABLE ' . $schema . '.items (object_id text, version text)');
        $database->exec('CREATE TABLE ' . $schema . '.pins (item_object_id text, item_version text, asset_object_id text, asset_version text)');
        $item = ['object_id' => 'urn:example:item', 'version' => 'v1'];
        $pins = [['object_id' => 'urn:example:asset', 'version' => 'v1']];
        $assertRows = fn () => ProjectionProbe::assertRebuilt($database, $projection, $item, $pins);
        // A rebuild response could claim success here, but no rows have been stored.
        expect($assertRows)->toThrow(AssertionFailedError::class);
        $database->exec('INSERT INTO ' . $schema . ".items VALUES ('urn:example:item', 'v1')");
        expect($assertRows)->toThrow(AssertionFailedError::class);
        $database->exec('INSERT INTO ' . $schema . ".pins VALUES ('urn:example:item', 'v1', 'urn:example:asset', 'v1')");
        $assertRows();
        $database->exec('UPDATE ' . $schema . ".pins SET asset_version = 'v2'");
        expect($assertRows)->toThrow(AssertionFailedError::class);
    } finally {
        if ($databaseCreated) {
            $admin->exec('DROP DATABASE "' . $name . '" WITH (FORCE)');
        }
        $admin->exec('DROP ROLE "' . $name . '"');
    }
});
