<?php

declare(strict_types=1);

namespace App\VaultBrowser;

use Tempest\Container\Container;
use Tempest\Http\Method;
use Tempest\Http\Request;
use Tempest\Http\Response;
use Tempest\Router\GenericResponseSender;
use Tempest\Router\ResponseSender;

/**
 * Transmit verified downloads without unbounded framework output buffering.
 *
 * Tempest's default File/Download sender buffers readfile() in ob_start().
 * This sender delegates ordinary responses and closes owned download streams
 * after transmission, including HEAD requests and failures.
 */
final readonly class BrowserResponseSender implements ResponseSender
{
    /**
     * Bind the fallback sender and request container used to distinguish HEAD responses.
     */
    public function __construct(
        private GenericResponseSender $default,
        private Container $container,
    ) {}

    /**
     * Send verified download headers and stream bytes directly after flushing buffers.
     *
     * HEAD requests send no body. A transmitted-byte mismatch raises
     * RuntimeException after output may have started. The download is closed
     * in all outcomes; ordinary responses use the default sender.
     */
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
