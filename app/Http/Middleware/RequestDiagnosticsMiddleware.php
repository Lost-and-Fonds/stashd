<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Tempest\Http\Request;
use Tempest\Http\Response;
use Tempest\Log\Logger;
use Tempest\Router\HttpMiddleware;
use Tempest\Router\HttpMiddlewareCallable;
use Tempest\Support\Priority;
use Throwable;

/**
 * Adds browser-visible API timing and a safe correlation ID. Only slow and
 * failed API requests are written to the application log, keeping normal
 * request traffic quiet while leaving evidence for intermittent sluggishness.
 */
#[Priority(Priority::FRAMEWORK - 28)]
final readonly class RequestDiagnosticsMiddleware implements HttpMiddleware
{
    private const float SLOW_REQUEST_MILLISECONDS = 500.0;

    public function __construct(
        private Logger $logger,
    ) {}

    public function __invoke(Request $request, HttpMiddlewareCallable $next): Response
    {
        $path = parse_url($request->path, PHP_URL_PATH) ?: $request->path;

        if (! str_starts_with($path, '/api/v1/')) {
            return $next($request);
        }

        $requestId = bin2hex(random_bytes(8));
        $startedAt = hrtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $throwable) {
            $this->logger->error('API request failed before producing a response.', [
                'request_id' => $requestId,
                'method' => $request->method->value,
                'path' => $path,
                'duration_ms' => $this->durationMilliseconds($startedAt),
                'exception' => $throwable::class,
            ]);

            throw $throwable;
        }

        $duration = $this->durationMilliseconds($startedAt);

        $response
            ->addHeader('Server-Timing', 'app;dur=' . number_format($duration, 1, '.', ''))
            ->addHeader('X-Stashd-Request-Id', $requestId);

        if ($response->status->isServerError()) {
            $this->logger->error('API request returned a server error.', [
                'request_id' => $requestId,
                'method' => $request->method->value,
                'path' => $path,
                'status' => $response->status->value,
                'duration_ms' => $duration,
            ]);
        } elseif ($duration >= self::SLOW_REQUEST_MILLISECONDS) {
            $this->logger->warning('Slow API request.', [
                'request_id' => $requestId,
                'method' => $request->method->value,
                'path' => $path,
                'status' => $response->status->value,
                'duration_ms' => $duration,
            ]);
        }

        return $response;
    }

    private function durationMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 1);
    }
}
