<?php

declare(strict_types=1);

use Stashd\PluginRuntime\Runner\PluginInvocationFailure;

it('keeps typed plugin error code and retryability', function (): void {
    $failure = PluginInvocationFailure::fromWire([
        'tag' => 'rate-limited',
        'value' => ['message' => 'quota exceeded', 'retryable' => true],
    ]);

    expect($failure->errorCode)->toBe('rate-limited')
        ->and($failure->getMessage())->toBe('quota exceeded')
        ->and($failure->retryable)->toBeTrue();
});

it('accepts legacy flat plugin errors during migration', function (): void {
    $failure = PluginInvocationFailure::fromWire([
        'code' => 'unavailable',
        'message' => 'old runtime response',
        'retryable' => true,
    ]);

    expect($failure->errorCode)->toBe('unavailable')
        ->and($failure->getMessage())->toBe('old runtime response')
        ->and($failure->retryable)->toBeTrue();
});
