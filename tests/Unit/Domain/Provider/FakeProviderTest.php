<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Provider;

use App\Providers\Fake\FakeProvider;
use App\Providers\StashdUri;

test('fake provider discovers three channel items initially', function (): void {
    $provider = new FakeProvider();
    $input = $provider->resolveInput(StashdUri::parse('fake://channel/demo'));
    $strategy = $provider->discoveryStrategies()[0];

    $items = $provider->discover($input, $strategy);

    expect($items)->toHaveCount(3)
        ->and($items[0]->providerItemId)->toBe('demo-episode-1')
        ->and($items[0]->description)->toBe('Fake episode 1 description.');
});

test('fake provider keeps three items across repeated discovery calls', function (): void {
    $provider = new FakeProvider();
    $input = $provider->resolveInput(StashdUri::parse('fake://channel/demo'));
    $strategy = $provider->discoveryStrategies()[0];

    $provider->discover($input, $strategy);
    $items = $provider->discover($input, $strategy);

    expect($items)->toHaveCount(3);
});

test('fake provider adds a fourth item once the sync generation is advanced', function (): void {
    $provider = new FakeProvider();
    $input = $provider->resolveInput(StashdUri::parse('fake://channel/demo'));
    $strategy = $provider->discoveryStrategies()[0];

    $provider->discover($input, $strategy);
    $provider->advanceSyncGeneration($input->providerInputId);
    $items = $provider->discover($input, $strategy);

    expect($items)->toHaveCount(4);
});

test('fake provider discovers twenty playlist items', function (): void {
    $provider = new FakeProvider();
    $input = $provider->resolveInput(StashdUri::parse('fake://playlist/long'));
    $strategy = $provider->discoveryStrategies()[0];

    $items = $provider->discover($input, $strategy);

    expect($items)->toHaveCount(20)
        ->and($items[0]->description)->toBe('Fake track 1 description.');
});

test('fake provider reports items as they are discovered', function (): void {
    $provider = new FakeProvider();
    $input = $provider->resolveInput(StashdUri::parse('fake://channel/demo'));
    $reported = [];

    $provider->discover($input, $provider->discoveryStrategies()[0], onDiscovered: static function ($item) use (&$reported): void {
        $reported[] = $item->providerItemId;
    });

    expect($reported)->toBe(['demo-episode-1', 'demo-episode-2', 'demo-episode-3']);
});
