<?php

declare(strict_types=1);

namespace App\VaultBrowser;

use Tempest\Http\HttpRequestFailed;
use Tempest\Http\Request;
use Tempest\Http\Response;
use Tempest\Router\Exceptions\ExceptionRenderer;
use Tempest\View\View;
use Throwable;

/** Preserve the browser's explicit error pages, including in local development. */
final readonly class BrowserExceptionRenderer implements ExceptionRenderer
{
    public function canRender(Throwable $throwable, Request $request): bool
    {
        return $throwable instanceof HttpRequestFailed && $throwable->cause?->body instanceof View && $throwable->cause->body->path === __DIR__ . '/browser.view.php';
    }

    public function render(Throwable $throwable): Response
    {
        if (! $throwable instanceof HttpRequestFailed || $throwable->cause === null) {
            throw new \LogicException('Expected a browser response failure.');
        }
        return $throwable->cause;
    }
}
