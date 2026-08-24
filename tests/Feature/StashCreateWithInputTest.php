<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Plugins\ExternalInputPluginRegistry;
use App\Plugins\PluginInputDefinition;
use App\Providers\Fake\FakeProvider;
use App\Stashes\CreateStashWithInitialInput;
use App\Stashes\DownloadPolicy;
use App\Stashes\InitialInputPersistence;
use App\Stashes\OrganizationMode;
use App\Stashes\StashId;
use App\Stashes\StashInputRecord;
use App\Stashes\StashRecord;
use App\Stashes\StashRepository;
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
        'Atomic Input',
        SyncMode::Automatic,
        DownloadPolicy::ManualDownload,
        OrganizationMode::Flat,
        null,
        'fake-input',
        ['reference' => 'fake://channel/atomic-input'],
        ['provider' => ['include_archived' => false]],
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

test('initial Input failure after Stash insert rolls the whole transaction back', function (): void {
    $definition = PluginInputDefinition::from([
        'kind' => 'input', 'id' => 'fake-input', 'provider_key' => 'fake', 'name' => 'Fake Input',
        'source_fields' => [['key' => 'reference', 'label' => 'Reference', 'type' => 'text', 'required' => true]],
    ], __DIR__) ?? throw new \RuntimeException('Failed to create fake Input definition.');
    $this->container->singleton(ExternalInputPluginRegistry::class, new ExternalInputPluginRegistry([
        $this->container->get(FakeProvider::class),
    ], [$definition]));
    $failure = new class implements InitialInputPersistence {
        public bool $stashWasInserted = false;

        public function persistDiscoveredInput(\App\Stashes\StashRecord $stash, \App\Stashes\PreflightExecutionResult $discovered, array $options = []): \App\Stashes\StashInputCommitResult
        {
            $this->stashWasInserted = StashRecord::count()->execute() === 1;

            throw new \RuntimeException('injected initial Input failure');
        }

        public function dispatchFollowups(\App\Stashes\StashRecord $stash, \App\Stashes\StashInputCommitResult $result): void {}
    };
    $this->container->singleton(InitialInputPersistence::class, $failure);

    expect(fn() => $this->container->get(CreateStashWithInitialInput::class)->execute(
        'Rollback Input',
        SyncMode::Automatic,
        DownloadPolicy::ManualDownload,
        OrganizationMode::Flat,
        null,
        'fake-input',
        ['reference' => 'fake://channel/rollback-input'],
        [],
    ))->toThrow(\RuntimeException::class, 'Failed to create stash and initial input.');

    expect($failure->stashWasInserted)->toBeTrue()
        ->and(StashRecord::count()->execute())->toBe(0)
        ->and(StashInputRecord::count()->execute())->toBe(0);
});

test('a declared Input can be added to an existing Stash', function (): void {
    $definition = PluginInputDefinition::from([
        'kind' => 'input', 'id' => 'fake-input', 'provider_key' => 'fake', 'name' => 'Fake Input',
        'source_fields' => [['key' => 'reference', 'label' => 'Reference', 'type' => 'text', 'required' => true]],
    ], __DIR__) ?? throw new \RuntimeException('Failed to create fake Input definition.');
    $this->container->singleton(ExternalInputPluginRegistry::class, new ExternalInputPluginRegistry([$this->container->get(FakeProvider::class)], [$definition]));
    $stash = $this->http->post('/api/v1/stashes', ['name' => 'Existing Stash'], headers: $this->authHeaders())->assertStatus(Status::CREATED);

    $result = $this->http->post('/api/v1/stashes/' . $stash->body['stash']['id'] . '/inputs', [
        'plugin' => 'fake-input',
        'source' => ['reference' => 'fake://channel/add-input'],
        'options' => ['provider' => ['include_archived' => false]],
    ], headers: $this->authHeaders())->assertStatus(Status::CREATED);

    expect(StashRecord::count()->execute())->toBe(1)
        ->and(StashInputRecord::count()->execute())->toBe(1)
        ->and($result->body['stash_id'])->toBe($stash->body['stash']['id'])
        ->and(StashInputRecord::select()->first()?->options?->provider)->toBe(['include_archived' => false]);
});

test('an invalid Input cannot alter an existing Stash', function (): void {
    $stash = $this->http->post('/api/v1/stashes', ['name' => 'Unchanged Stash'], headers: $this->authHeaders())->assertStatus(Status::CREATED);
    $this->http->post('/api/v1/stashes/' . $stash->body['stash']['id'] . '/inputs', [
        'plugin' => 'missing', 'source' => ['reference' => 'fake://channel/invalid'],
    ], headers: $this->authHeaders())->assertStatus(Status::BAD_REQUEST);

    expect(StashRecord::count()->execute())->toBe(1)
        ->and(StashInputRecord::count()->execute())->toBe(0)
        ->and(StashRecord::select()->first()?->name)->toBe('Unchanged Stash');
});

test('Input persistence failure leaves the existing Stash intact', function (): void {
    $definition = PluginInputDefinition::from([
        'kind' => 'input', 'id' => 'fake-input', 'provider_key' => 'fake', 'name' => 'Fake Input',
        'source_fields' => [['key' => 'reference', 'label' => 'Reference', 'type' => 'text', 'required' => true]],
    ], __DIR__) ?? throw new \RuntimeException('Failed to create fake Input definition.');
    $this->container->singleton(ExternalInputPluginRegistry::class, new ExternalInputPluginRegistry([$this->container->get(FakeProvider::class)], [$definition]));
    $stashResponse = $this->http->post('/api/v1/stashes', ['name' => 'Keep Me'], headers: $this->authHeaders())->assertStatus(Status::CREATED);
    $stash = $this->container->get(StashRepository::class)->find(StashId::parse($stashResponse->body['stash']['id']));
    $failure = new class implements InitialInputPersistence {
        public function persistDiscoveredInput(\App\Stashes\StashRecord $stash, \App\Stashes\PreflightExecutionResult $discovered, array $options = []): \App\Stashes\StashInputCommitResult
        {
            throw new \RuntimeException('injected existing-Input failure');
        }

        public function dispatchFollowups(\App\Stashes\StashRecord $stash, \App\Stashes\StashInputCommitResult $result): void {}
    };
    $this->container->singleton(InitialInputPersistence::class, $failure);

    expect(fn() => $this->container->get(CreateStashWithInitialInput::class)->addToExisting($stash, 'fake-input', ['reference' => 'fake://channel/existing-failure'], []))
        ->toThrow(\RuntimeException::class, 'Failed to create Input.');
    expect(StashRecord::count()->execute())->toBe(1)
        ->and(StashInputRecord::count()->execute())->toBe(0)
        ->and(StashRecord::select()->first()?->name)->toBe('Keep Me');
});
