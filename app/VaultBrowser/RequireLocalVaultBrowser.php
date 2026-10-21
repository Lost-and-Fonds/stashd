<?php

declare(strict_types=1);

namespace App\VaultBrowser;

use Tempest\Core\Environment;
use Tempest\Http\Request;
use Tempest\Http\Response;
use Tempest\Http\Responses\NotFound;
use Tempest\Router\HttpMiddleware;
use Tempest\Router\HttpMiddlewareCallable;

use function Tempest\env;

final readonly class RequireLocalVaultBrowser implements HttpMiddleware
{
    public function __construct(
        private Environment $environment,
    ) {}

    public function __invoke(Request $request, HttpMiddlewareCallable $next): Response
    {
        if (! $this->environment->isLocal() || env('VAULT_BROWSER_ENABLED', false) !== true) {
            return new NotFound();
        }

        return $next($request);
    }
}
