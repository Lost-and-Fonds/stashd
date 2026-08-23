<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Downloads\DownloaderInterface;
use App\Providers\Provider;
use Tempest\Container\Singleton;

#[Singleton]
final class ExternalInputPluginRegistry
{
    public function __construct() {}

    public function get(string $id): Provider
    {
        throw new \InvalidArgumentException("Unknown external Input plugin: {$id}");
    }

    public function find(string $id): ?Provider
    {
        return null;
    }

    public function findDownloader(string $id): ?DownloaderInterface
    {
        return null;
    }

    /** @return list<Provider> */
    public function providers(): array
    {
        return [];
    }
}
