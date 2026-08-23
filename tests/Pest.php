<?php

declare(strict_types=1);

use App\Auth\AuthService;
use Tempest\Database\Database;
use Tempest\Database\Query;
use Tempest\Framework\Testing\Http\TestResponseHelper;
use Tempest\Http\Cookie\Cookie;
use Tests\IntegrationTestCase;

// Defined here (global namespace) rather than in AuthTest.php so it's loaded
// by every --parallel worker process regardless of which test files that
// worker is assigned — PHP falls back to the global namespace for functions
// not found in the caller's own namespace.
function useSessionCookieFrom(TestResponseHelper $response): void
{
    $values = $response->response->getHeader('set-cookie')?->values ?? [];

    foreach ($values as $value) {
        $cookie = Cookie::createFromString($value);

        if ($cookie->key === AuthService::SESSION_COOKIE) {
            $_COOKIE[AuthService::SESSION_COOKIE] = $cookie->value;

            return;
        }
    }

    throw new RuntimeException('Response did not set a ' . AuthService::SESSION_COOKIE . ' cookie.');
}

function requireExternalInputPluginRuntime(object $test): void
{
    $socket = getenv('STASHD_PLUGIN_HOST_SOCKET');
    $component = getenv('STASHD_PLUGIN_COMPONENT');

    if (! is_string($socket) || trim($socket) === '' || ! is_string($component) || ! is_file($component)) {
        $test->markTestSkipped('External Input plugin runtime is provided by the plugin lifecycle script.');
    }
}

// PostgreSQL schema introspection is defined here (global namespace), like
// useSessionCookieFrom above, so every --parallel worker loads it regardless
// of which test files it was assigned.
function schemaTableExists(Database $database, string $table): bool
{
    $row = $database->fetchFirst(new Query(
        'SELECT tablename FROM pg_tables WHERE schemaname = current_schema() AND tablename = ?',
        bindings: [$table],
    ));

    return $row !== null;
}

/** @return list<string> */
function schemaColumns(Database $database, string $table): array
{
    $rows = $database->fetch(new Query(
        'SELECT column_name AS name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?',
        bindings: [$table],
    ));

    return array_values(array_column($rows ?? [], 'name'));
}

/** @return list<string> */
function schemaIndexes(Database $database, string $table): array
{
    $rows = $database->fetch(new Query(
        'SELECT indexname AS name FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ?',
        bindings: [$table],
    ));

    return array_values(array_column($rows ?? [], 'name'));
}

// Computed and applied once, at file-load time, before the first test's
// FrameworkKernel boots: database/storage config is resolved eagerly during
// boot (not lazily on first use), so beforeEach() is too late to redirect it
// for test 1 — by test 2 the putenv() calls below have already mutated the
// process-wide env, which is why a per-test-only version of this masked the
// bug after the first test. TEST_TOKEN is ParaTest's per-worker identifier
// (unset outside --parallel), mirrored from Tempest's own internalStorage
// keying so each parallel worker gets an isolated data dir/db file.
$worker = getenv('TEST_TOKEN') ?: 'default';
$data = sys_get_temp_dir() . '/stashd-test/' . $worker . '/data';
$media = sys_get_temp_dir() . '/stashd-test/' . $worker . '/media';

if (! is_string(getenv('STASHD_PLUGIN_FIXTURE_DIR')) || trim((string) getenv('STASHD_PLUGIN_FIXTURE_DIR')) === '') {
    putenv('STASHD_PLUGIN_FIXTURE_DIR=' . dirname(__DIR__) . '/tests/fixtures/providers/youtube/http');
}

if (! is_string(getenv('YOUTUBE_DATA_API_KEY')) || trim((string) getenv('YOUTUBE_DATA_API_KEY')) === '') {
    putenv('YOUTUBE_DATA_API_KEY=fixture-secret-do-not-cross-wasm');
}

if (! is_string(getenv('STASHD_PLUGIN_HELPER_EXECUTABLE')) || trim((string) getenv('STASHD_PLUGIN_HELPER_EXECUTABLE')) === '') {
    putenv('STASHD_PLUGIN_HELPER_EXECUTABLE=' . dirname(__DIR__) . '/tests/fixtures/providers/youtube/fake-yt-dlp.sh');
}

if (! is_string(getenv('STASHD_BROADCAST_HTTP_FIXTURE_DIR')) || trim((string) getenv('STASHD_BROADCAST_HTTP_FIXTURE_DIR')) === '') {
    putenv('STASHD_BROADCAST_HTTP_FIXTURE_DIR=' . dirname(__DIR__) . '/tests/fixtures/media_servers/http');
}

foreach ([$data, $media] as $directory) {
    if (! is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
}

putenv('STASHD_DATA_PATH=' . $data);
putenv('STASHD_MEDIA_PATH=' . $media);
$_ENV['STASHD_DATA_PATH'] = $data;
$_ENV['STASHD_MEDIA_PATH'] = $media;

$databaseBase = preg_replace('/[^a-zA-Z0-9_]+/', '_', (string) (getenv('DB_DATABASE') ?: 'stashd'));
$databaseBase = trim((string) $databaseBase, '_') ?: 'stashd';
$workerName = preg_replace('/[^a-zA-Z0-9_]+/', '_', $worker);
$workerName = trim((string) $workerName, '_') ?: 'default';
$databaseName = substr($databaseBase, 0, 40) . '_test_' . substr($workerName, 0, 16);

$host = (string) (getenv('DB_HOST') ?: '127.0.0.1');
$port = (string) (getenv('DB_PORT') ?: '5432');
$username = (string) (getenv('DB_USERNAME') ?: 'postgres');
$password = (string) (getenv('DB_PASSWORD') ?: '');
$adminDatabase = (string) (getenv('DB_ADMIN_DATABASE') ?: 'postgres');
$pdo = new PDO(
    "pgsql:host={$host};port={$port};dbname={$adminDatabase}",
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$databaseExists = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
$databaseExists->execute([$databaseName]);

if ($databaseExists->fetchColumn() === false) {
    try {
        $pdo->exec(sprintf('CREATE DATABASE "%s"', $databaseName));
    } catch (PDOException $exception) {
        if ($exception->getCode() !== '42P04') {
            throw $exception;
        }
    }
}

putenv('DB_DATABASE=' . $databaseName);
$_ENV['DB_DATABASE'] = $databaseName;

pest()->extend(IntegrationTestCase::class)
    ->beforeEach(function () use ($media): void {
        $wipe = null;
        $wipe = static function (string $directory) use (&$wipe): void {
            if (! is_dir($directory)) {
                return;
            }

            $entries = scandir($directory) ?: [];

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $directory . '/' . $entry;

                if (is_dir($path)) {
                    $wipe($path);
                    rmdir($path);
                } else {
                    unlink($path);
                }
            }
        };

        $wipe($media);

        if (! is_dir($media)) {
            mkdir($media, 0775, true);
        }

        // Tests that exercise the cookie-authenticated session (AuthTest,
        // Phase2HardeningTest) write directly to this superglobal since
        // Tempest's request mapper reads cookies from it. Pest runs every
        // test in the same process, so it must not leak between tests.
        $_COOKIE = [];
        unset($_SERVER['REMOTE_ADDR']);

        $this->useTestingDatabase();
        $this->database->reset();

    })
    ->in('Feature', '../plugins/podcast/tests');
