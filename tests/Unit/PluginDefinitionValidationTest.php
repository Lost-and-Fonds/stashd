<?php

declare(strict_types=1);

use App\Plugins\PluginInputDefinition;
use App\Providers\InputOptionType;

it('keeps valid input option shapes and helper declarations', function (): void {
    $root = sys_get_temp_dir() . '/stashd-plugin-definition-' . bin2hex(random_bytes(4));
    mkdir($root . '/helpers', 0700, true);
    file_put_contents($root . '/helpers/yt-dlp', 'helper');

    try {
        $definition = PluginInputDefinition::from([
            'kind' => 'input',
            'id' => 'youtube',
            'input_options' => [
                ['key' => 'include_shorts', 'label' => 'Include Shorts', 'type' => 'bool', 'default' => false],
            ],
            'source_fields' => [
                ['key' => 'account', 'label' => 'Account', 'type' => 'text', 'required' => true],
                ['key' => 'collection', 'label' => 'Collection', 'type' => 'enum', 'choices' => ['one', 'two']],
                ['key' => 'recursive', 'label' => 'Recursive', 'type' => 'bool'],
            ],
            'helpers' => ['yt-dlp' => ['executable' => 'helpers/yt-dlp', 'network' => true]],
        ], $root);

        expect($definition?->options[0]->type)->toBe(InputOptionType::Bool)
            ->and($definition?->helper?->name)->toBe('yt-dlp')
            ->and($definition?->normalizeSource(['account' => 'hazel', 'collection' => 'one', 'recursive' => true]))->toBe(['account' => 'hazel', 'collection' => 'one', 'recursive' => true]);
    } finally {
        unlink($root . '/helpers/yt-dlp');
        rmdir($root . '/helpers');
        rmdir($root);
    }
});

it('skips input options and helpers with invalid generic shapes', function (): void {
    $definition = PluginInputDefinition::from([
        'kind' => 'input',
        'id' => 'youtube',
        'input_options' => [
            ['key' => 12, 'label' => 'Broken', 'type' => 'bool', 'default' => false],
            ['key' => 'broken-choices', 'label' => 'Broken', 'type' => 'bool', 'default' => false, 'choices' => 'not-a-list'],
        ],
        'helpers' => ['broken' => ['executable' => false]],
    ], sys_get_temp_dir());

    expect($definition?->options)->toBe([])
        ->and($definition?->helper)->toBeNull();
});
