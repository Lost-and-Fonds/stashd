<?php

declare(strict_types=1);

use Stashd\PluginRuntime\Runner\PluginInvocationFailure;
use Stashd\PluginRuntime\Capabilities\Invocation;

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

it('redacts invocation-scoped raw credentials and cookie lines', function (): void {
    $root = sys_get_temp_dir() . '/stashd-credential-redaction-' . bin2hex(random_bytes(5));
    mkdir($root, 0700);
    $invocation = new Invocation(__DIR__, $root . '/staging', [], sensitiveValues: ["po-token\ncookie-row"]);

    expect($invocation->redactSensitive('po-token cookie-row'))->toBe('[REDACTED] [REDACTED]');

    $invocation->close();
    rmdir($root);
});
