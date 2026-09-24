<?php

declare(strict_types=1);

use App\Plugins\ExternalInputPluginRegistry;
use App\Plugins\PluginInputDefinition;
use App\System\Secret\SecretRepository;
use App\System\Secret\SecretsService;
use Tempest\Http\Status;

beforeEach(function (): void {
    $definition = PluginInputDefinition::from([
        'kind' => 'input',
        'id' => 'youtube',
        'name' => 'YouTube',
        'credentials' => [[
            'key' => 'youtube-data-api',
            'label' => 'YouTube Data API key',
            'description' => 'Improves complete channel discovery.',
            'secret_key' => 'youtube_data_api_key',
            'secret_type' => 'api_key',
        ], [
            'key' => 'youtube-cookies',
            'label' => 'YouTube cookies.txt',
            'secret_key' => 'youtube_cookies_txt',
            'input_type' => 'file',
            'raw_access' => true,
        ], [
            'key' => 'youtube-po-token',
            'label' => 'YouTube PO token',
            'secret_key' => 'youtube_po_token',
            'raw_access' => true,
        ]],
        'http_grants' => [[
            'allowed_prefixes' => ['https://www.googleapis.com/youtube/v3/'],
            'operations' => ['complete'],
            'credential' => ['name' => 'youtube-data-api', 'parameter' => 'key', 'placement' => 'query'],
        ]],
    ], __DIR__) ?? throw new RuntimeException('Failed to create plugin definition.');

    $this->container->singleton(ExternalInputPluginRegistry::class, new ExternalInputPluginRegistry([], [$definition]));
});

test('declared credentials expose safe configured state', function (): void {
    $response = $this->http->get('/api/v1/plugin-credentials', headers: $this->authHeaders())->assertOk();

    expect($response->body)->toBe([
        'plugins' => [[
            'key' => 'youtube',
            'label' => 'YouTube',
            'credentials' => [[
                'key' => 'youtube-data-api',
                'label' => 'YouTube Data API key',
                'description' => 'Improves complete channel discovery.',
                'required' => false,
                'input_type' => 'password',
                'configured' => false,
            ], [
                'key' => 'youtube-cookies',
                'label' => 'YouTube cookies.txt',
                'description' => null,
                'required' => false,
                'input_type' => 'file',
                'configured' => false,
            ], [
                'key' => 'youtube-po-token',
                'label' => 'YouTube PO token',
                'description' => null,
                'required' => false,
                'input_type' => 'password',
                'configured' => false,
            ]],
        ]],
    ])->and(json_encode($response->body, JSON_THROW_ON_ERROR))
        ->not->toContain('youtube_data_api_key')
        ->not->toContain('encrypted_value');
});

test('a declared credential is replaced through encrypted secret storage without leaking it', function (): void {
    $value = 'test-youtube-key-not-production';
    $updated = $this->http->put('/api/v1/plugin-credentials/youtube/youtube-data-api', ['value' => $value], headers: $this->authHeaders())
        ->assertOk();
    expect(json_encode($updated->body, JSON_THROW_ON_ERROR))
        ->not->toContain($value)
        ->not->toContain('youtube_data_api_key');

    $secret = $this->container->get(SecretRepository::class)->findByKey('youtube_data_api_key');
    expect($secret)->not->toBeNull()
        ->and($secret?->encryptedValue)->not->toBe($value)
        ->and($this->container->get(SecretsService::class)->get('youtube_data_api_key'))->toBe($value);

    $metadata = $this->http->get('/api/v1/plugin-credentials', headers: $this->authHeaders())->assertOk();
    expect($metadata->body['plugins'][0]['credentials'][0]['configured'])->toBeTrue()
        ->and(json_encode($metadata->body, JSON_THROW_ON_ERROR))
        ->not->toContain($value)
        ->not->toContain($secret?->encryptedValue)
        ->not->toContain('youtube_data_api_key');

    $definition = $this->container->get(ExternalInputPluginRegistry::class)->definition('youtube');
    expect($definition?->httpGrants($this->container->get(SecretsService::class), 'complete')[0]->credential?->value)->toBe($value);
});

test('plugin-declared raw credentials are encrypted and granted only when requested', function (): void {
    $definition = PluginInputDefinition::from([
        'kind' => 'input',
        'id' => 'youtube',
        'name' => 'YouTube',
        'credentials' => [
            ['key' => 'youtube-cookies', 'label' => 'YouTube cookies.txt', 'secret_key' => 'youtube_cookies_txt', 'input_type' => 'file', 'raw_access' => true],
            ['key' => 'youtube-po-token', 'label' => 'YouTube PO token', 'secret_key' => 'youtube_po_token', 'raw_access' => true],
            ['key' => 'youtube-data-api', 'label' => 'API key', 'secret_key' => 'youtube_data_api_key'],
        ],
    ], __DIR__) ?? throw new RuntimeException('Failed to create plugin definition.');
    $cookieJar = "# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t0\tSID\tfixture-session\n";
    $this->http->put('/api/v1/plugin-credentials/youtube/youtube-cookies', ['value' => $cookieJar], headers: $this->authHeaders())->assertOk();
    $this->http->put('/api/v1/plugin-credentials/youtube/youtube-po-token', ['value' => 'fixture-po-token'], headers: $this->authHeaders())->assertOk();

    expect($definition->rawCredentials($this->container->get(SecretsService::class)))->toBe([
        'youtube-cookies' => $cookieJar,
        'youtube-po-token' => 'fixture-po-token',
    ])->and($definition->credential('youtube-cookies')?->toArray(true))->toMatchArray([
        'input_type' => 'file',
        'configured' => true,
    ]);
});

test('plugin credentials are limited to 256 KiB', function (): void {
    $this->http->put('/api/v1/plugin-credentials/youtube/youtube-data-api', ['value' => str_repeat('x', 262145)], headers: $this->authHeaders())
        ->assertStatus(Status::BAD_REQUEST);
});

test('undeclared credentials cannot be written', function (): void {
    $this->http->put('/api/v1/plugin-credentials/youtube/not-declared', ['value' => 'test-value'], headers: $this->authHeaders())
        ->assertStatus(Status::NOT_FOUND);

    expect($this->container->get(SecretsService::class)->has('youtube_data_api_key'))->toBeFalse();
});

test('credential updates require authentication', function (): void {
    $this->http->put('/api/v1/plugin-credentials/youtube/youtube-data-api', ['value' => 'test-value'])
        ->assertStatus(Status::FORBIDDEN);
});
