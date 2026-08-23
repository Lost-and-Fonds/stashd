<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Support\Http\CurlClient;
use RuntimeException;
use Stashd\NativeRuntime\Capabilities\HostHttpTransport;
use Stashd\NativeRuntime\Capabilities\TransportResponse;

final readonly class NativeBroadcastHttpTransport implements HostHttpTransport
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

        $response = CurlClient::send($method, $url, $headers, $body, timeoutSeconds: 30);
        if ($response === null) {
            throw new RuntimeException('Approved HTTP request could not be started.');
        }

        return new TransportResponse($response['status'], [], [$response['body']]);
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
