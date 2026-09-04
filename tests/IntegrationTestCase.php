<?php

declare(strict_types=1);

namespace Tests;

use App\Auth\AuthService;
use App\Auth\UserRepository;
use App\Jobs\MessengerWorkerRunner;
use App\Plugins\ExternalInputPluginRegistry;
use App\Plugins\PluginInputDefinition;
use App\Providers\Fake\FakeProvider;
use App\Stashes\StashItemRecord;
use App\Vault\MediaItemRecord;
use Tempest\Database\Direction;
use Tempest\Database\PrimaryKey;
use Tempest\Discovery\DiscoveryLocation;
use Tempest\Framework\Testing\IntegrationTest;
use Tempest\Http\Status;

abstract class IntegrationTestCase extends IntegrationTest
{
    protected string $root = __DIR__ . '/../';

    public function hasExternalInputPlugins(): bool
    {
        return $this->container->get(\App\Plugins\ExternalInputPluginRegistry::class)->providers() !== [];
    }

    /** @return array{Authorization: string} */
    public function authHeaders(): array
    {
        $auth = $this->container->get(AuthService::class);
        $users = $this->container->get(UserRepository::class);

        if ($auth->isSetupRequired()) {
            $user = $users->createAdmin(
                username: 'owner',
                passwordHash: password_hash('secret-password', PASSWORD_DEFAULT),
            );
        } else {
            $user = $users->findByUsername('owner')
                ?? throw new \RuntimeException('Expected admin user for auth headers.');
        }

        $token = $auth->createApiToken($user, 'test');

        return ['Authorization' => 'Bearer ' . $token['token']];
    }

    /**
     * @param  list<string>  $scopes
     * @return array{Authorization: string}
     */
    public function scopedAuthHeaders(array $scopes): array
    {
        $created = $this->http->post('/api/v1/auth/tokens', [
            'name' => 'scoped-' . bin2hex(random_bytes(3)),
            'scopes' => $scopes,
        ], headers: $this->authHeaders())->assertStatus(Status::CREATED);

        return ['Authorization' => 'Bearer ' . $created->body['token']];
    }

    public function processAllJobs(): void
    {
        $runner = $this->container->get(MessengerWorkerRunner::class);
        $runner->drain('interactive');
        $runner->drain('background');
    }

    /** Adds a stash holding one fake channel input, via the real add-input flow. */
    public function bootstrapFakeChannelStash(string $channel): string
    {
        $headers = $this->authHeaders();
        $this->registerFakeInputPlugin();

        $stash = $this->http->post('/api/v1/stashes', ['name' => 'Stash ' . $channel], headers: $headers)
            ->assertStatus(Status::CREATED);
        $stashId = $stash->body['stash']['id'];

        $this->http->post('/api/v1/stashes/' . $stashId . '/inputs', [
            'plugin' => 'fake-input',
            'source' => ['reference' => 'fake://channel/' . $channel],
        ], headers: $headers)->assertStatus(Status::ACCEPTED);
        $this->processAllJobs();

        return $stashId;
    }

    /** @return array{0: array{Authorization: string}, 1: string, 2: string} */
    public function bootstrapFakeDownloadStash(string $channel = 'download-demo'): array
    {
        $headers = $this->authHeaders();
        $this->registerFakeInputPlugin();

        $stash = $this->http->post('/api/v1/stashes', [
            'name' => $channel . '-' . bin2hex(random_bytes(3)),
            'download_policy' => 'manual_download',
        ], headers: $headers)->assertStatus(Status::CREATED);
        $stashId = $stash->body['stash']['id'];

        $this->http->post('/api/v1/stashes/' . $stashId . '/inputs', [
            'plugin' => 'fake-input',
            'source' => ['reference' => 'fake://channel/' . $channel],
        ], headers: $headers)->assertStatus(Status::ACCEPTED);
        $this->processAllJobs();

        $stashItem = StashItemRecord::select()
            ->where('stashId', $stashId)
            ->orderBy('position', Direction::ASC)
            ->first();
        $media = MediaItemRecord::findById(new PrimaryKey((string) $stashItem->mediaItemId));

        return [$headers, $stashId, (string) $media->id];
    }

    private function registerFakeInputPlugin(): void
    {
        $definition = PluginInputDefinition::from([
            'kind' => 'input',
            'id' => 'fake-input',
            'provider_key' => 'fake',
            'name' => 'Fake Input',
            'source_fields' => [['key' => 'reference', 'label' => 'Reference', 'type' => 'text', 'required' => true]],
        ], __DIR__) ?? throw new \RuntimeException('Failed to create fake Input definition.');

        $this->container->singleton(ExternalInputPluginRegistry::class, new ExternalInputPluginRegistry([
            $this->container->get(FakeProvider::class),
        ], [$definition]));
    }

    /**
     * @return array{0: array{Authorization: string}, 1: string, 2: string, 3: string}
     */
    public function bootstrapFakeDownloadBroadcast(string $channel = 'broadcast-demo'): array
    {
        [$headers, $stashId, $mediaItemId, $broadcastId] = $this->bootstrapJellyfinDownloadBroadcast($channel);

        return [$headers, $stashId, $mediaItemId, $broadcastId];
    }

    /** @return array{0: array{Authorization: string}, 1: string, 2: string, 3: string, 4: string} */
    public function bootstrapJellyfinDownloadBroadcast(string $channel = 'jellyfin-broadcast-demo'): array
    {
        [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash($channel);

        $server = $this->http->post('/api/v1/connections', [
            'plugin_key' => 'jellyfin',
            'name' => 'Fixture Jellyfin',
            'endpoint' => 'https://jellyfin.test',
            'token' => 'fixture-jellyfin-token',
            'settings' => [
                'library_id' => 'shows-lib',
                'library_name' => 'TV Shows',
            ],
        ], headers: $headers)->assertStatus(Status::CREATED);

        $connectionId = $server->body['connection']['id'];

        $create = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
            'type' => 'jellyfin',
            'name' => 'Jellyfin Demo Series',
            'slug' => $channel . '-jellyfin-' . bin2hex(random_bytes(3)),
            'settings' => [
                'media_server_connection_id' => $connectionId,
            ],
        ], headers: $headers)->assertStatus(Status::CREATED);

        return [$headers, $stashId, $mediaItemId, $create->body['broadcast']['id'], $connectionId];
    }

    protected function setUp(): void
    {
        putenv('ENVIRONMENT=testing');
        $_ENV['ENVIRONMENT'] = 'testing';
        $_SERVER['ENVIRONMENT'] = 'testing';

        parent::setUp();
    }

    /** @return DiscoveryLocation[] */
    protected function discoverTestLocations(): array
    {
        return [];
    }
}
