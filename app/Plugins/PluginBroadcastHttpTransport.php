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
    public function __construct(private ?string $fixtureDirectory = null) {}

    public function request(string $method, string $url, array $headers, ?string $body): TransportResponse
    {
        if ($method === '') {
            throw new RuntimeException('Approved HTTP method is empty.');
        }

        if ($this->fixtureDirectory !== null && trim($this->fixtureDirectory) !== '') {
            $map = json_decode((string) file_get_contents($this->fixtureDirectory . '/map.json'), true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($map)) {
                throw new RuntimeException('Broadcast HTTP fixture map is invalid.');
            }
            $filename = $map[$url] ?? $map[strtoupper($method) . ' ' . $url] ?? null;

            if (! is_string($filename)) {
                foreach ($map as $pattern => $candidate) {
                    if (is_string($pattern) && is_string($candidate) && str_starts_with($url, $pattern)) {
                        $filename = $candidate;

                        break;
                    }
                }
            }

            if (! is_string($filename)) {
                throw new RuntimeException('Broadcast HTTP fixture was not found.');
            }

            if (str_starts_with($filename, 'status:')) {
                return new TransportResponse((int) substr($filename, 7), [], []);
            }
            $path = $this->fixtureDirectory . '/' . $filename;

            return new TransportResponse($this->fixtureStatus($filename), [], [file_get_contents($path) ?: '']);
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

    private function fixtureStatus(string $filename): int
    {
        if (str_starts_with($filename, 'status:')) {
            return (int) substr($filename, 7);
        }

        return match ($filename) {
            'unavailable.txt' => 404,
            'auth_rejected.json' => 401,
            'rate_limited.json' => 429,
            default => 200,
        };
    }
}
