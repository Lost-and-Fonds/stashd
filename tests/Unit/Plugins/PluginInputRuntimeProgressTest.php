<?php

declare(strict_types=1);

use App\Plugins\PluginInputRuntime;

test('plugin progress forwards an expected byte total without requiring a fraction', function (): void {
    $runtime = (new ReflectionClass(PluginInputRuntime::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(PluginInputRuntime::class, 'capabilityProgress');
    $update = null;
    $method->invoke($runtime, static function (...$values) use (&$update): void {
        $update = $values;
    }, ['stage' => 'Downloading', 'fraction' => null, 'size_bytes' => 98765, 'size_estimated' => true]);

    expect($update)->toBe(['Downloading', null, 98765, true]);
});
