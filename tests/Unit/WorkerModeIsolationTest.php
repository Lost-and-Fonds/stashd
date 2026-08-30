<?php

declare(strict_types=1);

use App\Auth\AuthContext;
use App\Auth\UserRecord;
use App\Auth\UserRole;
use Tempest\Container\GenericContainer;

test('Tempest worker reset clears Stashd request authentication state', function (): void {
    $container = new GenericContainer();
    $container
        ->singleton(AuthContext::class, new AuthContext())
        ->addResettable(AuthContext::class);

    $context = $container->get(AuthContext::class);
    $context->set(new UserRecord('request-a', 'unused', UserRole::Admin));

    expect($context->user()?->username)->toBe('request-a');

    // WorkerModeApplication invokes this same container reset during kernel
    // shutdown. Keeping this seam avoids requiring FrankenPHP in unit tests.
    $container->reset();

    $requestB = $container->get(AuthContext::class);

    expect($requestB->user())->toBeNull()
        ->and($requestB->principal())->toBeNull();
});
