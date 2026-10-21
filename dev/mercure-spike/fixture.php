<?php

declare(strict_types=1);

/**
 * Load disposable credentials created explicitly for this synthetic local spike.
 *
 * This fixture is outside application discovery and never handles operational data.
 */
function spike_config(): array
{
    return json_decode(file_get_contents(__DIR__ . '/../../tmp/mercure-spike/credentials.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Mint a short-lived, exact-topic token for Mercure 0.24's legacy claim format.
 *
 * These test-only HMAC signers are not an application authentication service.
 */
function spike_token(array $config, string $action, ?string $topic = null, int $ttl = 300): string
{
    $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $header = $encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $payload = $encode(json_encode([
        'iat' => time(),
        'exp' => time() + $ttl,
        'mercure' => [$action => [$topic ?? $config['topic']]],
    ], JSON_THROW_ON_ERROR));
    $key = $config[$action === 'publish' ? 'publisher' : 'subscriber'];
    return $header . '.' . $payload . '.' . $encode(hash_hmac('sha256', $header . '.' . $payload, $key, true));
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $config = spike_config();
    $action = $argv[1] ?? '';
    if ($action === 'token') {
        echo spike_token($config, $argv[2] ?? 'subscribe', $argv[3] ?? null, (int) ($argv[4] ?? 300));
        exit;
    }
    if ($action !== 'publish') {
        fwrite(STDERR, "Usage: lerd php dev/mercure-spike/fixture.php publish [message]\n");
        exit(2);
    }
    $id = 'urn:uuid:' . bin2hex(random_bytes(16));
    $curl = curl_init($config['hub_url']);
    try {
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'topic' => $config['topic'],
                'private' => 'on',
                'id' => $id,
                'data' => json_encode(['synthetic' => true, 'message' => $argv[2] ?? 'Hello from separate PHP CLI', 'id' => $id], JSON_THROW_ON_ERROR),
            ]),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . spike_token($config, 'publish')],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
        ]);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($body === false || $status !== 200 || $body !== $id) {
            throw new RuntimeException('Publication failed: HTTP ' . $status . ' ' . curl_error($curl));
        }
        echo json_encode(['status' => $status, 'id' => $id], JSON_THROW_ON_ERROR) . "\n";
    } finally {
        unset($curl);
    }
}
