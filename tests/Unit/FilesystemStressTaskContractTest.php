<?php

declare(strict_types=1);

use Composer\InstalledVersions;

test('the task lifecycle adapter runs against its reviewed Prompts internals', function (string $path, string $sha256): void {
    expect(InstalledVersions::getVersion('laravel/prompts'))->toBe('0.3.26.0');
    expect(InstalledVersions::getReference('laravel/prompts'))->toBe('2d1e9bf6bab8d3f383c254db7f2b4ea8816d55e0');
    $root = InstalledVersions::getInstallPath('laravel/prompts');
    expect($root)->toBeString();
    expect(hash_file('sha256', $root . '/' . $path))->toBe($sha256);
})->with([
    'fork, signals, IPC and task destructor' => ['src/Task.php', '07147577ebbd5b3831c0a2620054db8d088b991b8139eca4e7ba64be13fe6592'],
    'inherited rendering and terminal lifecycle' => ['src/Prompt.php', 'd9f26891bd0c71de3f79d548c0634ef0e9beda9c5a27a879cb64c57d42f1fdd8'],
    'logger protocol and protected socket' => ['src/Support/Logger.php', 'f02d2b019cd59d1d2ad77f3503920164e6be8f95bb200a23dbc8e57192f0aaef'],
    'unchanged default task renderer' => ['src/Themes/Default/TaskRenderer.php', 'a5ac427e68a521c63f29dff2d015bc6a245daad2c3c8ab38accab970937935ab'],
]);
