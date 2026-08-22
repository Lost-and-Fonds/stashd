<?php

declare(strict_types=1);

namespace App\Http\Routing;

use Generator;
use Stringable;
use Tempest\Container\Decorates;
use Tempest\Http\Response;
use Tempest\Http\Responses\EventStream;
use Tempest\Router\ResponseSender;

/**
 * Tempest's stock GenericResponseSender only special-cases a Generator body
 * wrapped in EventStream (SSE framing) -- any other Generator body (e.g.
 * bounded-chunk file reads, needed so a hundreds-of-MB response is never
 * buffered whole in memory) falls through to
 * `echo $body`, which throws because Generator has no __toString. That
 * throws after headers -- including Content-Length -- are already sent,
 * corrupting the response instead of cleanly failing. This decorates the
 * framework sender to stream those chunks directly, mirroring how
 * GenericResponseSender itself streams EventStream bodies.
 */
#[Decorates(ResponseSender::class)]
final readonly class StreamingResponseSender implements ResponseSender
{
    public function __construct(
        private ResponseSender $inner,
    ) {}

    public function send(Response $response): Response
    {
        if ($response instanceof EventStream || ! $response->body instanceof Generator) {
            return $this->inner->send($response);
        }

        if (! headers_sent()) {
            foreach ($response->headers as $key => $header) {
                foreach ($header->values as $value) {
                    header("{$key}: " . self::stringify($value), replace: false);
                }
            }

            http_response_code($response->status->value);
        }

        if (ob_get_level() > 0) {
            ob_end_flush();
        }

        foreach ($response->body as $chunk) {
            if (connection_aborted() !== 0) {
                break;
            }

            echo self::stringify($chunk);

            if (ob_get_level() > 0) {
                ob_flush();
            }

            flush();
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        return $response;
    }

    /** Header values and stream chunks are always scalar/string in practice; this just proves it to PHPStan. */
    private static function stringify(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? '1' : '0',
            $value instanceof Stringable => (string) $value,
            default => '',
        };
    }
}
