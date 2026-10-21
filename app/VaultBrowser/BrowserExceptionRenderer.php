<?php

declare(strict_types=1);

namespace App\VaultBrowser;

use Tempest\Http\HttpRequestFailed;
use Tempest\Http\Request;
use Tempest\Http\Response;
use Tempest\Router\Exceptions\ExceptionRenderer;
use Tempest\View\View;
use Throwable;

/**
 * Keep explicit Vault browser error pages visible in local development.
 *
 * Only HTTP failures already carrying the browser view are handled; unrelated
 * exceptions remain available to other framework renderers.
 */
final readonly class BrowserExceptionRenderer implements ExceptionRenderer
{
    /**
     * Recognize HTTP failures whose response body uses the explicit Vault browser view.
     */
    public function canRender(Throwable $throwable, Request $request): bool
    {
        return $throwable instanceof HttpRequestFailed && $throwable->cause?->body instanceof View && $throwable->cause->body->path === __DIR__ . '/browser.view.php';
    }

    /**
     * Return the browser response attached to an HTTP failure.
     *
     * Raises LogicException if called without the failure type and response
     * required by canRender().
     */
    public function render(Throwable $throwable): Response
    {
        if (! $throwable instanceof HttpRequestFailed || $throwable->cause === null) {
            throw new \LogicException('Expected a browser response failure.');
        }
        return $throwable->cause;
    }
}
