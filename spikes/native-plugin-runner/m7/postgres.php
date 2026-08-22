<?php

declare(strict_types=1);

$environment = [];
foreach (file(__DIR__ . '/../../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $environment[trim($key)] = trim($value, " \"'");
}

$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $environment['DB_HOST'] ?? 'lerd-postgres', $environment['DB_PORT'] ?? '5432', $environment['DB_DATABASE'] ?? 'stashd_testing');
$pdo = new PDO($dsn, $environment['DB_USERNAME'] ?? 'postgres', $environment['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TEMP TABLE native_plugin_m7_smoke (id text primary key, payload jsonb)');
$statement = $pdo->prepare('INSERT INTO native_plugin_m7_smoke (id, payload) VALUES (:id, CAST(:payload AS jsonb))');
$statement->execute(['id' => 'active-package', 'payload' => json_encode(['plugin' => 'm7-example', 'version' => '1.0.0'], JSON_THROW_ON_ERROR)]);
$row = $pdo->query('SELECT payload->>\'plugin\' AS plugin FROM native_plugin_m7_smoke WHERE id = \'active-package\'')->fetch(PDO::FETCH_ASSOC);
if (($row['plugin'] ?? null) !== 'm7-example') {
    throw new RuntimeException('PostgreSQL native plugin state round-trip failed');
}
echo "M7 PostgreSQL integration: PASS\n";
