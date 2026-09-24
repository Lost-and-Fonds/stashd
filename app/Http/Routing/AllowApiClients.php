<?php

declare(strict_types=1);

namespace App\Http\Routing;

use Attribute;
use Tempest\Router\PreventCrossSiteRequestsMiddleware;
use Tempest\Router\Route;
use Tempest\Router\RouteDecorator;

/** Allows machine clients (curl, API tokens) to POST without Sec-Fetch headers. */
#[Attribute(Attribute::TARGET_CLASS)]
final class AllowApiClients implements RouteDecorator
{
    public function decorate(Route $route): Route
    {
        $route->without = [
            ...$route->without,
            PreventCrossSiteRequestsMiddleware::class,
        ];

        return $route;
    }
}
