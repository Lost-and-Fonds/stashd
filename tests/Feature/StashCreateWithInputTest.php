<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Plugins\ExternalInputPluginRegistry;
use App\Plugins\PluginInputDefinition;
use App\Providers\Fake\FakeProvider;
use App\Stashes\CreateStashWithInitialInput;
use App\Stashes\DownloadPolicy;
use App\Stashes\OrganizationMode;
use App\Stashes\StashInputRecord;
use App\Stashes\StashRecord;
use App\Stashes\SyncMode;
use Tempest\Http\Status;

test('a declared Input source creates a stash and initial input atomically', function (): void {
    $definition = PluginInputDefinition::from([
        'kind' => 'input', 'id' => 'fake-input', 'provider_key' => 'fake', 'name' => 'Fake Input',
        'source_fields' => [['key' => 'reference', 'label' => 'Reference', 'type' => 'text', 'required' => true]],
    ], __DIR__) ?? throw new \RuntimeException('Failed to create fake Input definition.');
    $this->container->singleton(ExternalInputPluginRegistry::class, new ExternalInputPluginRegistry([
        $this->container->get(FakeProvider::class),
    ], [$definition]));

    $created = $this->container->get(CreateStashWithInitialInput::class)->execute(
        'Atomic Input', SyncMode::Automatic, DownloadPolicy::ManualDownload, OrganizationMode::Flat, null,
        'fake-input', ['reference' => 'fake://channel/atomic-input'], ['provider' => ['include_archived' => false]],
    );

    expect((string) $created->id)->toStartWith('stash_');
    expect(StashRecord::count()->execute())->toBe(1)
        ->and(StashInputRecord::count()->execute())->toBe(1)
        ->and(StashInputRecord::select()->first()?->stashId->toString())->toBe((string) $created->id)
        ->and(StashInputRecord::select()->first()?->options?->provider)->toBe(['include_archived' => false]);
});

test('an unknown Input plugin cannot leave a stash behind', function (): void {
    $response = $this->http->post('/api/v1/stashes/with-input', [
        'name' => 'Must Not Exist',
        'input' => ['plugin' => 'missing', 'source' => ['anything' => 'value']],
    ], headers: $this->authHeaders());

    $response->assertStatus(Status::BAD_REQUEST);
    expect(StashRecord::count()->execute())->toBe(0)
        ->and(StashInputRecord::count()->execute())->toBe(0);
});
