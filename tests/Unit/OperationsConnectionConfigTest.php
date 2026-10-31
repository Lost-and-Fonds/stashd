<?php

declare(strict_types=1);

use App\Integration\OperationsConfig;
use App\Integration\OperationsConnectionConfig;

test('ordinary Tempest configuration preserves explicit settings and supplies schema on each connection', function (): void {
    $config = new OperationsConnectionConfig(new OperationsConfig('pgsql:host=example;dbname=operations', 'owner', schema: 'private_operations'));
    expect($config->dsn)->toBe('pgsql:host=example;dbname=operations;options=--search_path=private_operations');
    expect($config->usePersistentConnection)->toBeFalse();
    expect(new OperationsConnectionConfig(new OperationsConfig())->dsn)->toBe('');
});

test('a competing PostgreSQL startup options field cannot override the application schema', function (): void {
    expect(fn () => new OperationsConnectionConfig(new OperationsConfig('pgsql:host=example;options=--search_path=public', schema: 'private_operations')))
        ->toThrow(InvalidArgumentException::class);
});
