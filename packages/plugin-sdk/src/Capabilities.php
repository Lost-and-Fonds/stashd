<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

use RuntimeException;

interface Logger
{
    public function info(string $message): void;
    public function error(string $message): void;
}

interface ProgressReporter
{
    public function report(string $stage): void;
}

interface ReadableResource
{
    public function read(int $maximumBytes = 65536): string;
    public function isEof(): bool;
    public function close(): void;
}

final readonly class HttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public array $headers = [],
        public ?string $inlineBody = null,
        public ?ReadableResource $resource = null,
    ) {
    }

    public function isInline(): bool
    {
        return $this->resource === null;
    }
    public function body(): string
    {
        if ($this->inlineBody !== null) {
            return $this->inlineBody;
        }
        throw new RuntimeException('response body is an opaque resource');
    }
}

interface HttpClient
{
    /** @param array<string, string> $headers */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?string $credential = null): HttpResponse;
}

interface StagingArea
{
    public function write(string $relativePath, string $content, ?string $mediaType = null): StagedArtifact;
    public function stage(string $relativePath, ?string $mediaType = null): StagedArtifact;
}

final class UnavailableHttpClient implements HttpClient
{
    /** @param array<string, string> $headers */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?string $credential = null): HttpResponse
    {
        throw new CapabilityUnavailableException('HTTP is a fixture-only capability in M4');
    }
}

final class NullLogger implements Logger
{
    public function info(string $message): void
    {
    }
    public function error(string $message): void
    {
    }
}

final class NullProgressReporter implements ProgressReporter
{
    public function report(string $stage): void
    {
    }
}

final readonly class PluginContext
{
    public function __construct(
        public Logger $logger = new NullLogger(),
        public ProgressReporter $progress = new NullProgressReporter(),
        public HttpClient $http = new UnavailableHttpClient(),
        public ?StagingArea $staging = null,
    ) {
    }
}
