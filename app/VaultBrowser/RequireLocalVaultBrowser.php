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

/**
 * Gate the Vault browser on both local environment and explicit enablement.
 *
 * This middleware hides its routes with a 404 when either condition fails;
 * it is an environment gate, not user authentication.
 */
final readonly class RequireLocalVaultBrowser implements HttpMiddleware
{
    /**
     * Capture the application environment used when deciding whether browsing is allowed.
     */
    public function __construct(
        private Environment $environment,
    ) {}

    /**
     * Return 404 unless local mode and VAULT_BROWSER_ENABLED are both active.
     *
     * Allowed requests continue through the next middleware without modification.
     */
    public function __invoke(Request $request, HttpMiddlewareCallable $next): Response
    {
        if (! $this->environment->isLocal() || env('VAULT_BROWSER_ENABLED', false) !== true) {
            return new NotFound();
        }

        return $next($request);
    }
}
