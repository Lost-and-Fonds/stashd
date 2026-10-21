<?php

declare(strict_types=1);

namespace App\VaultBrowser;

use Tempest\Container\Container;
use Tempest\Http\Method;
use Tempest\Http\Request;
use Tempest\Http\Response;
use Tempest\Router\GenericResponseSender;
use Tempest\Router\ResponseSender;

/** Tempest's default File/Download sender buffers readfile() in an unbounded ob_start(). */
final readonly class BrowserResponseSender implements ResponseSender
{
    public function __construct(
        private GenericResponseSender $default,
        private Container $container,
    ) {}

    public function send(Response $response): Response
    {
        if (! $response instanceof VerifiedDownload) {
            return $this->default->send($response);
        }
        try {
            http_response_code($response->status->value);
            foreach ($response->headers as $header) {
                foreach ($header->values as $value) {
                    header($header->name . ': ' . (string) $value, replace: false);
                }
            }
            // Flush framework/SAPI output buffers before streaming a potentially large file.
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            if ($this->container->get(Request::class)->method !== Method::HEAD) {
                rewind($response->stream);
                if (fpassthru($response->stream) !== $response->size) {
                    throw new \RuntimeException('Download transmission failed.');
                }
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            return $response;
        } finally {
            $response->close();
        }
    }
}
