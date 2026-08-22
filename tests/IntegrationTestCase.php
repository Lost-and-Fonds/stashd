<?php

declare(strict_types=1);

namespace Tests;

use Tempest\Framework\Testing\IntegrationTest;

abstract class IntegrationTestCase extends IntegrationTest
{
    protected string $root = __DIR__ . '/../';

    /** @return array{Authorization: string} */
    public function authHeaders(): array
    {
        $auth = $this->container->get(\App\Auth\AuthService::class);
        $users = $this->container->get(\App\Auth\UserRepository::class);

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
     * @param list<string> $scopes
     *
     * @return array{Authorization: string}
     */
    public function scopedAuthHeaders(array $scopes): array
    {
        $created = $this->http->post('/api/v1/auth/tokens', [
            'name' => 'scoped-' . bin2hex(random_bytes(3)),
            'scopes' => $scopes,
        ], headers: $this->authHeaders())->assertStatus(\Tempest\Http\Status::CREATED);

        return ['Authorization' => 'Bearer ' . $created->body['token']];
    }

    public function processAllJobs(): void
    {
        $worker = $this->container->get(\App\Jobs\JobWorkerService::class);

        while ($worker->processNextJob()) {
        }
    }

    /** Adds a stash holding one fake channel input, via the real add-input flow. */
    public function bootstrapFakeChannelStash(string $channel): string
    {
        $headers = $this->authHeaders();

        $stash = $this->http->post('/api/v1/stashes', ['name' => 'Stash ' . $channel], headers: $headers)
            ->assertStatus(\Tempest\Http\Status::CREATED);
        $stashId = $stash->body['stash']['id'];

        $preflight = $this->http->post('/api/v1/commands', [
            'type' => 'stash.preflight',
            'options' => ['source_uri' => 'fake://channel/' . $channel],
        ], headers: $headers)->assertStatus(\Tempest\Http\Status::CREATED);
        $this->processAllJobs();

        $this->http->post('/api/v1/stashes/' . $stashId . '/inputs', [
            'preflight_command_id' => $preflight->body['command_id'],
        ], headers: $headers)->assertStatus(\Tempest\Http\Status::CREATED);
        $this->processAllJobs();

        return $stashId;
    }

    /** @return array{0: array{Authorization: string}, 1: string, 2: string} */
    public function bootstrapFakeDownloadStash(string $channel = 'download-demo'): array
    {
        $headers = $this->authHeaders();

        $stash = $this->http->post('/api/v1/stashes', [
            'name' => $channel . '-' . bin2hex(random_bytes(3)),
            'download_policy' => 'manual_download',
        ], headers: $headers)->assertStatus(\Tempest\Http\Status::CREATED);
        $stashId = $stash->body['stash']['id'];

        $preflight = $this->http->post('/api/v1/commands', [
            'type' => 'stash.preflight',
            'options' => ['source_uri' => 'fake://channel/' . $channel],
        ], headers: $headers)->assertStatus(\Tempest\Http\Status::CREATED);
        $this->processAllJobs();

        $this->http->post('/api/v1/stashes/' . $stashId . '/inputs', [
            'preflight_command_id' => $preflight->body['command_id'],
        ], headers: $headers)->assertStatus(\Tempest\Http\Status::CREATED);
        $this->processAllJobs();

        $stashItem = \App\Stashes\StashItemRecord::select()
            ->where('stashId', $stashId)
            ->orderBy('position', \Tempest\Database\Direction::ASC)
            ->first();
        $media = \App\Vault\MediaItemRecord::findById(new \Tempest\Database\PrimaryKey((string) $stashItem->mediaItemId));

        return [$headers, $stashId, (string) $media->id];
    }

    /** @return array{0: array{Authorization: string}, 1: string, 2: string} */
    public function bootstrapYouTubeDownloadStash(string $slug = 'youtube-download-demo'): array
    {
        $headers = $this->authHeaders();

        $stash = $this->http->post('/api/v1/stashes', [
            'name' => $slug . '-' . bin2hex(random_bytes(3)),
            'download_policy' => 'manual_download',
        ], headers: $headers)->assertStatus(\Tempest\Http\Status::CREATED);
        $stashId = $stash->body['stash']['id'];

        $preflight = $this->http->post('/api/v1/commands', [
            'type' => 'stash.preflight',
            'options' => [
                'source_uri' => 'https://www.youtube.com/channel/UCStashdDemoCh0012345678',
                'source_title' => 'YouTube Download Demo',
            ],
        ], headers: $headers)->assertStatus(\Tempest\Http\Status::CREATED);
        $this->processAllJobs();

        $this->http->post('/api/v1/stashes/' . $stashId . '/inputs', [
            'preflight_command_id' => $preflight->body['command_id'],
        ], headers: $headers)->assertStatus(\Tempest\Http\Status::CREATED);
        $this->processAllJobs();

        $stashItem = \App\Stashes\StashItemRecord::select()
            ->where('stashId', $stashId)
            ->orderBy('position', \Tempest\Database\Direction::ASC)
            ->first();
        $media = \App\Vault\MediaItemRecord::findById(new \Tempest\Database\PrimaryKey((string) $stashItem->mediaItemId));

        return [$headers, $stashId, (string) $media->id];
    }

    /**
     * @return array{0: array{Authorization: string}, 1: string, 2: string, 3: string}
     */
    public function bootstrapFakeDownloadBroadcast(string $channel = 'broadcast-demo'): array
    {
        [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash($channel);

        $create = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
            'type' => 'jellyfin',
            'name' => 'Broadcast Demo',
            'slug' => $channel . '-broadcast-' . bin2hex(random_bytes(3)),
        ], headers: $headers)->assertStatus(\Tempest\Http\Status::CREATED);

        return [$headers, $stashId, $mediaItemId, $create->body['broadcast']['id']];
    }

    /** @return array{0: array{Authorization: string}, 1: string, 2: string, 3: string, 4: string} */
    public function bootstrapJellyfinDownloadBroadcast(string $channel = 'jellyfin-broadcast-demo'): array
    {
        [$headers, $stashId, $mediaItemId] = $this->bootstrapFakeDownloadStash($channel);

        $server = $this->http->post('/api/v1/media-servers', [
            'type' => 'jellyfin',
            'name' => 'Fixture Jellyfin',
            'base_uri' => 'https://jellyfin.test',
            'token' => 'fixture-jellyfin-token',
            'settings' => [
                'library_id' => 'shows-lib',
                'library_name' => 'TV Shows',
            ],
        ], headers: $headers)->assertStatus(\Tempest\Http\Status::CREATED);

        $connectionId = $server->body['media_server']['id'];

        $create = $this->http->post('/api/v1/stashes/' . $stashId . '/broadcasts', [
            'type' => 'jellyfin',
            'name' => 'Jellyfin Demo Series',
            'slug' => $channel . '-jellyfin-' . bin2hex(random_bytes(3)),
            'settings' => [
                'media_server_connection_id' => $connectionId,
                'auto_trigger_scan' => true,
            ],
        ], headers: $headers)->assertStatus(\Tempest\Http\Status::CREATED);

        return [$headers, $stashId, $mediaItemId, $create->body['broadcast']['id'], $connectionId];
    }

    protected function setUp(): void
    {
        putenv('ENVIRONMENT=testing');
        $_ENV['ENVIRONMENT'] = 'testing';
        $_SERVER['ENVIRONMENT'] = 'testing';

        parent::setUp();
    }

    /** @return \Tempest\Discovery\DiscoveryLocation[] */
    protected function discoverTestLocations(): array
    {
        return [];
    }
}
