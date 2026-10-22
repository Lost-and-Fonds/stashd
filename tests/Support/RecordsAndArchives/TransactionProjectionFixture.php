<?php

namespace Tests\Support\RecordsAndArchives;

use App\RecordsAndArchives\ArchiveProjection;
use App\RecordsAndArchives\AssetArchive;
use App\RecordsAndArchives\ItemArchive;
use App\RecordsAndArchives\Vault;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Provision only parent-named disposable PostgreSQL fixtures for independent SQL probes.
 *
 * Administration uses an explicit private test configuration, never application
 * database settings. Preservation and projection rebuilding remain production code.
 */
final readonly class TransactionProjectionFixture
{
    /**
     * Validate fixture identity before connecting or performing destructive SQL.
     *
     * The generated role owns only its generated database. Private credentials
     * remain outside the Vault and cleanup removes only the validated fixture.
     */
    public function run(array $request, Vault $vault, ItemArchive $items, AssetArchive $assets): array
    {
        $fixture = $request['fixture'];
        $name = $fixture['database'] ?? '';
        if (! is_string($name) || ! preg_match('/^stashd_tx_test_[a-f0-9]{16}$/D', $name) || $fixture !== ['database' => $name, 'username' => $name, 'schema' => $name]) {
            throw new InvalidArgumentException('Only the exact generated test database, role and schema may be used.');
        }
        $path = dirname($request['root']) . '/projection-' . $name . '.json';
        if ($request['operation'] === 'projection.describe' || $request['operation'] === 'projection.dispose') {
            $configPath = dirname(__DIR__, 3) . '/tmp/transaction-projection-admin.json';
            if (! is_file($configPath)) {
                throw new RuntimeException('An explicit private local projection test-admin configuration is required.');
            }
            $config = $this->readJson($configPath);
            if (! is_string($config['username'] ?? null) || ! is_string($config['password'] ?? null)) {
                throw new InvalidArgumentException('Explicit test-admin credentials are required.');
            }
            if (($config['dsn'] ?? null) !== 'pgsql:host=lerd-postgres;port=5432;dbname=postgres') {
                throw new InvalidArgumentException('Projection fixtures require the local PostgreSQL test administrator.');
            }
            $admin = new PDO($config['dsn'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if ($request['operation'] === 'projection.dispose') {
                $admin->exec('DROP DATABASE IF EXISTS "' . $name . '" WITH (FORCE)');
                $admin->exec('DROP ROLE IF EXISTS "' . $name . '"');
                if (is_file($path)) {
                    unlink($path);
                }
                return ['status' => 'disposed'];
            }
            $password = bin2hex(random_bytes(24));
            $quotedPassword = $admin->quote($password);
            if ($quotedPassword === false) {
                throw new RuntimeException('Could not quote disposable fixture password.');
            }
            $admin->exec('CREATE ROLE "' . $name . '" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD ' . $quotedPassword);
            try {
                $admin->exec('CREATE DATABASE "' . $name . '" OWNER "' . $name . '"');
                $descriptor = [
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
                file_put_contents($path, json_encode($descriptor, JSON_THROW_ON_ERROR));
                chmod($path, 0o600);
                ProjectionProbe::connect($descriptor, $name)->exec('CREATE SCHEMA "' . $name . '"');
                return $descriptor;
            } catch (\Throwable $error) {
                $admin->exec('DROP DATABASE IF EXISTS "' . $name . '" WITH (FORCE)');
                $admin->exec('DROP ROLE "' . $name . '"');
                throw $error;
            }
        }
        $descriptor = $this->readJson($path);
        $database = ProjectionProbe::connect($descriptor, $name);
        if ($request['operation'] === 'projection.destroy') {
            $database->exec('DROP SCHEMA "' . $name . '" CASCADE');
            $exists = $database->prepare('SELECT count(*) FROM pg_namespace WHERE nspname = ?');
            if ($exists === false) {
                throw new RuntimeException('Could not query fixture schema existence.');
            }
            $exists->execute([$name]);
            return ['verified_absent' => (int) $exists->fetchColumn() === 0];
        }
        if ($request['operation'] === 'projection.rebuild') {
            return ['items' => new ArchiveProjection($vault, $items, $assets)->rebuild($database, $name)];
        }
        throw new InvalidArgumentException('Unsupported projection fixture operation.');
    }

    /**
     * Read private fixture configuration and reject non-object or unreadable JSON.
     */
    private function readJson(string $path): array
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException('Could not read private projection fixture configuration.');
        }
        $data = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new InvalidArgumentException('Fixture configuration must be a JSON object.');
        }
        return $data;
    }
}
