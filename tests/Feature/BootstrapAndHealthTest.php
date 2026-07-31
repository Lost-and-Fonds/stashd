<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Commands\CommandRecord;
use App\Jobs\JobRecord;
use App\System\Boot\BootstrapService;
use App\System\Boot\MigrationRunner;
use App\System\Boot\SqliteConfigurator;
use App\System\Storage\StorageCapabilityChecker;
use App\System\Storage\StorageCheckRecord;
use App\System\Storage\StorageLocationKey;
use App\System\Storage\StorageLocationRecord;
use App\System\Storage\StorageLocationState;
use App\System\Storage\StorageRootService;
use Tempest\Database\Config\DatabaseConfig;
use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Config\PostgresConfig;
use Tempest\Database\Database;
use Tempest\Database\Migrations\Migration;
use Tempest\Database\Migrations\RunnableMigrations;
use Tempest\Database\PrimaryKey;

test('boot creates schema command and job records', function (): void {
    $bootstrap = $this->container->get(BootstrapService::class);
    $result = $bootstrap->boot($this->container->get(DatabaseConfig::class));

    expect($result['command_id'])->toStartWith('cmd_')
        ->and($result['job_id'])->toStartWith('job_')
        ->and(CommandRecord::findById(new PrimaryKey($result['command_id'])))->not->toBeNull()
        ->and(JobRecord::findById(new PrimaryKey($result['job_id'])))->not->toBeNull()
        ->and(StorageLocationRecord::select()->all())->toHaveCount(5)
        ->and(StorageCheckRecord::select()->all())->not->toBeEmpty();
});

test('boot skips SQLite setup for a PostgreSQL config', function (): void {
    $result = $this->container->get(BootstrapService::class)
        ->boot(new PostgresConfig());

    expect($result['command_id'])->toStartWith('cmd_')
        ->and($result['job_id'])->toStartWith('job_');
});

test('PostgreSQL replays every Stashd migration', function (): void {
    if ($this->container->get(Database::class)->dialect !== DatabaseDialect::POSTGRESQL) {
        $this->markTestSkipped('Run with DB_CONNECTION=pgsql to exercise PostgreSQL migrations.');
    }

    $migrations = $this->container->get(RunnableMigrations::class);

    expect(Migration::all())->toHaveCount(iterator_count($migrations->up()));
});

test('health endpoint returns ok after boot', function (): void {
    $this->container->get(BootstrapService::class)
        ->boot($this->container->get(DatabaseConfig::class));

    $response = $this->http->get('/health');

    $response->assertOk();
    expect($response->body['status'])->toBe('ok')
        ->and($response->body['version'])->not->toBeEmpty();
});

test('provider registry resolves fake uris', function (): void {
    $registry = $this->container->get(\App\Providers\ProviderRegistry::class);
    $provider = $registry->resolveForUri(\App\Providers\StashdUri::parse('fake://channel/demo'));

    expect($provider->key())->toBe('fake');
});

test('provider registry resolves youtube uris', function (): void {
    $registry = $this->container->get(\App\Providers\ProviderRegistry::class);
    $provider = $registry->resolveForUri(\App\Providers\StashdUri::parse('https://www.youtube.com/watch?v=demoVideo01'));

    expect($provider->key())->toBe('youtube');
});

test('sqlite pragmas are enabled on the tempest connection', function (): void {
    if ($this->container->get(Database::class)->dialect === DatabaseDialect::POSTGRESQL) {
        $this->markTestSkipped('SQLite-specific: pragmas, WAL, and file backups have no PostgreSQL equivalent.');
    }

    $sqlite = $this->container->get(\Tempest\Database\Config\SQLiteConfig::class);
    $configurator = $this->container->get(SqliteConfigurator::class);

    $configurator->configure($sqlite);
    $configurator->enableWriteAheadLogging($sqlite);
    $pragmas = $configurator->readPragmas();

    expect($pragmas['foreign_keys'])->toBe(1)
        ->and((int) $pragmas['busy_timeout'])->toBe(5000);

    if ($sqlite->path !== ':memory:') {
        expect($pragmas['journal_mode'])->toBe('wal');
    }
});

test('enabling WAL does not retain a SQLite statement that blocks schema migrations', function (): void {
    if ($this->container->get(Database::class)->dialect === DatabaseDialect::POSTGRESQL) {
        $this->markTestSkipped('SQLite-specific: pragmas, WAL, and file backups have no PostgreSQL equivalent.');
    }

    $sqlite = $this->container->get(\Tempest\Database\Config\SQLiteConfig::class);
    $configurator = $this->container->get(SqliteConfigurator::class);
    $database = $this->container->get(\Tempest\Database\Database::class);

    $configurator->configure($sqlite);
    $database->execute(new \Tempest\Database\Query('CREATE INDEX `stashes_slug` ON `stashes` (`name`)'));
    $configurator->enableWriteAheadLogging($sqlite);
    $database->execute(new \Tempest\Database\Query('DROP INDEX IF EXISTS `stashes_slug`'));

    expect($database->fetchFirst(new \Tempest\Database\Query(
        "SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'stashes_slug'",
    )))->toBeNull();
});

test('migration runner skips backup when no pending migrations remain', function (): void {
    if ($this->container->get(Database::class)->dialect === DatabaseDialect::POSTGRESQL) {
        $this->markTestSkipped('SQLite-specific: pragmas, WAL, and file backups have no PostgreSQL equivalent.');
    }

    $runner = $this->container->get(MigrationRunner::class);
    $sqlite = $this->container->get(\Tempest\Database\Config\SQLiteConfig::class);
    $data = getenv('STASHD_DATA_PATH') ?: '/tmp/stashd-test/data';
    $backupGlob = $data . '/backups/stashd-before-migration-*.sqlite';

    foreach (glob($backupGlob) ?: [] as $stale) {
        if (is_file($stale)) {
            @unlink($stale);
        }
    }

    expect($runner->hasPendingMigrations())->toBeFalse();

    $runner->run($sqlite);

    expect(glob($backupGlob) ?: [])->toBeEmpty();
});

test('migration runner detects pending migrations when a record is missing', function (): void {
    $runner = $this->container->get(MigrationRunner::class);

    expect($runner->hasPendingMigrations())->toBeFalse();

    $database = $this->container->get(\Tempest\Database\Database::class);
    $database->execute(new \Tempest\Database\Query(
        'DELETE FROM migrations WHERE name = ?',
        bindings: ['2026_06_16_create_foundation_schema'],
    ));

    expect($runner->hasPendingMigrations())->toBeTrue();
});

test('migration runner creates a timestamped backup file before applying migrations', function (): void {
    if ($this->container->get(Database::class)->dialect === DatabaseDialect::POSTGRESQL) {
        $this->markTestSkipped('SQLite-specific: pragmas, WAL, and file backups have no PostgreSQL equivalent.');
    }

    $runner = $this->container->get(MigrationRunner::class);
    $sqlite = $this->container->get(\Tempest\Database\Config\SQLiteConfig::class);
    $data = getenv('STASHD_DATA_PATH') ?: '/tmp/stashd-test/data';
    $backupGlob = $data . '/backups/stashd-before-migration-*.sqlite';

    foreach (glob($backupGlob) ?: [] as $stale) {
        if (is_file($stale)) {
            @unlink($stale);
        }
    }

    expect(is_file($sqlite->path))->toBeTrue();

    $method = new \ReflectionMethod(MigrationRunner::class, 'backupIfExists');
    $method->invoke($runner, $sqlite->path);

    expect(glob($backupGlob) ?: [])->not->toBeEmpty();
});

test('storage roots are created and writable', function (): void {
    $roots = $this->container->get(StorageRootService::class);
    $config = $this->container->get(\App\Config\StashdConfig::class);

    $roots->ensureDirectories();

    expect(is_dir($config->dataPath))->toBeTrue()
        ->and(is_dir($config->vaultPath()))->toBeTrue()
        ->and(is_dir($config->broadcastsPath()))->toBeTrue()
        ->and(is_dir($config->tempPath()))->toBeTrue()
        ->and(is_dir($config->cachePath()))->toBeTrue()
        ->and(is_writable($config->dataPath))->toBeTrue()
        ->and(is_writable($config->vaultPath()))->toBeTrue()
        ->and(is_writable($config->broadcastsPath()))->toBeTrue();
});

test('vault to broadcasts hardlink probe succeeds on a shared filesystem', function (): void {
    $this->container->get(StorageRootService::class)->ensureDirectories();
    $checker = $this->container->get(StorageCapabilityChecker::class);

    $checker->checkAll();
    $result = $checker->checkVaultBroadcastHardlink();

    expect($result->ok)->toBeTrue()
        ->and($result->errorCode)->toBeNull();

    $vault = StorageLocationRecord::select()->where('key', StorageLocationKey::Vault)->first();
    $broadcasts = StorageLocationRecord::select()->where('key', StorageLocationKey::Broadcasts)->first();

    expect($vault?->supportsHardlinks)->toBeTrue()
        ->and($broadcasts?->supportsHardlinks)->toBeTrue();
});

test('an unwritable storage root is recorded as unwritable, not silently ready', function (): void {
    $checker = $this->container->get(StorageCapabilityChecker::class);
    $tmp = sys_get_temp_dir() . '/stashd-storage-unwritable-' . bin2hex(random_bytes(4));
    mkdir($tmp);
    chmod($tmp, 0o555);

    if (is_writable($tmp)) {
        chmod($tmp, 0o775);
        rmdir($tmp);
        $this->markTestSkipped('Running as a user that bypasses directory permission bits (likely root); cannot force an unwritable directory.');
    }

    $record = $checker->checkRoot(StorageLocationKey::Vault, $tmp);

    chmod($tmp, 0o775);
    rmdir($tmp);

    expect($record->state)->toBe(StorageLocationState::Unwritable)
        ->and($record->writable)->toBeFalse();
});

test('detailed health reports vault broadcast hardlink status', function (): void {
    $this->container->get(BootstrapService::class)
        ->boot($this->container->get(DatabaseConfig::class));

    $response = $this->http->get('/api/v1/system/health', headers: $this->authHeaders());

    $response
        ->assertOk()
        ->assertHeaderMatches('Server-Timing', 'app;dur=%f')
        ->assertHeaderMatches('X-Stashd-Request-Id', '%s');
    expect($response->body['storage']['vault_broadcast_hardlink'])->toBeTrue()
        ->and($response->body['status'])->toBe('ok');

    $locations = $response->body['storage']['locations'];
    expect($locations)->not->toBeEmpty();

    foreach ($locations as $location) {
        expect($location)->toHaveKeys(['free_bytes', 'total_bytes']);
    }
});
