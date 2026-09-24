<?php

declare(strict_types=1);

namespace App\Plugins;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Stashd\PluginRuntime\Capabilities\HostHttpTransport;
use Stashd\PluginRuntime\Capabilities\TransportResponse;

final readonly class PluginBroadcastHttpTransport implements HostHttpTransport
{
    public function request(string $method, string $url, array $headers, ?string $body): TransportResponse
    {
        if ($method === '') {
            throw new RuntimeException('Approved HTTP method is empty.');
        }

        try {
            $response = (new Client())->request($method, $url, [
                'allow_redirects' => false,
                'body' => $body,
                'headers' => $headers,
                'http_errors' => false,
                'timeout' => 30,
            ]);
        } catch (GuzzleException $exception) {
            throw new RuntimeException('Approved HTTP request could not be started.', 0, $exception);
        }

        return new TransportResponse($response->getStatusCode(), $this->responseHeaders($response), [$response->getBody()->getContents()]);
    }

    /** @return array<string, string> */
    private function responseHeaders(ResponseInterface $response): array
    {
        $headers = $response->getHeaders();
        $values = array_map(static fn(array $header): string => implode(', ', $header), $headers);

        /** @var array<string, string> $responseHeaders */
        $responseHeaders = array_combine(array_keys($headers), $values) ?: [];

        return $responseHeaders;
    }

}
