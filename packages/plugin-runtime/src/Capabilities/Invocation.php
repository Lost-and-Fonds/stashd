<?php

declare(strict_types=1);

namespace Stashd\PluginRuntime\Capabilities;

use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use RuntimeException;
use Stashd\PluginRuntime\Sandbox\SandboxPolicy;
use Stashd\PluginSdk\HttpResponse;
use Stashd\PluginSdk\ReadableResource;
use Tempest\DateTime\Duration;
use Tempest\Process\GenericProcessExecutor;
use Tempest\Process\PendingProcess;

final class Invocation
{
    private bool $active = true;

    /** @var array<string, FileResource> */
    private array $resources = [];

    /** @var list<array{kind:string, value:string}> */
    private array $events = [];

    /** @var array<string, CredentialGrant> */
    private array $credentials = [];

    /** @var array<string, string> */
    private array $assets = [];

    /** @var array<string, HelperGrant> */
    private array $helpers = [];

    private InvocationStaging $staging;

    private string $resourceRoot;

    /**
     * @param  list<string>  $allowedPrefixes
     * @param  list<CredentialGrant>  $credentials
     * @param  list<HelperGrant>  $helpers
     */
    public function __construct(
        private string $packageRoot,
        private string $stagingRoot,
        private array $allowedPrefixes,
        array $credentials = [],
        private ?string $assetRoot = null,
        array $helpers = [],
        private HostHttpTransport $transport = new FixtureTransport(),
        private int $inlineLimit = 65536,
        private int $maxRedirects = 3,
        private SandboxPolicy $sandboxPolicy = new SandboxPolicy(),
    ) {
        $this->packageRoot = $this->withinRoot($packageRoot, 'package');

        if (! is_dir($stagingRoot) && ! mkdir($stagingRoot, 0700, true) && ! is_dir($stagingRoot)) {
            throw new RuntimeException('staging root could not be created');
        }
        $this->stagingRoot = $this->withinRoot($stagingRoot, 'staging');
        $this->resourceRoot = $this->stagingRoot . '/.resources';
        mkdir($this->resourceRoot, 0700, true);

        foreach ($credentials as $credential) {
            $this->credentials[$credential->reference] = $credential;
        }

        foreach ($helpers as $helper) {
            $this->helpers[$helper->name] = $helper;
        }
        $this->staging = new InvocationStaging($this->stagingRoot);
    }

    /** @param array<string, string> $headers */
    public function http(string $method, string $url, array $headers = [], ?string $body = null, ?string $credential = null): HttpResponse
    {
        $this->assertActive();
        $current = $url;
        $grant = $credential === null ? null : ($this->credentials[$credential] ?? throw new CapabilityDenied('credential is not granted'));

        for ($redirect = 0; ; $redirect++) {
            $current = $this->normalizeUrl($current);
            $origin = $this->origin($current);
            $this->assertAllowedDestination($current);
            $requestUrl = $this->safeUrl($current, $grant, $origin);
            $requestHeaders = $this->safeHeaders($headers, $grant, $origin);
            $response = $this->transport->request(strtoupper($method), $requestUrl, $requestHeaders, $body);
            $location = $response->headers['Location'] ?? $response->headers['location'] ?? null;

            if ($location !== null && $response->status >= 300 && $response->status < 400) {
                if ($redirect >= $this->maxRedirects) {
                    throw new CapabilityDenied('redirect limit exceeded');
                }
                $current = (string) UriResolver::resolve(new Uri($current), new Uri($location));

                continue;
            }
            $resourcePath = $this->resourceRoot . '/' . bin2hex(random_bytes(10)) . '.body';
            $handle = fopen($resourcePath, 'wb');

            if ($handle === false) {
                throw new RuntimeException('response resource could not be created');
            }
            $size = 0;

            foreach ($response->chunks as $chunk) {
                $size += strlen($chunk);
                fwrite($handle, $chunk);
            }
            fclose($handle);

            if ($size <= $this->inlineLimit) {
                $bodyValue = (string) file_get_contents($resourcePath);
                unlink($resourcePath);

                return new HttpResponse($response->status, $response->headers, $bodyValue);
            }
            $resource = new FileResource($resourcePath, function () use ($resourcePath): void {
                if (is_file($resourcePath)) {
                    unlink($resourcePath);
                }
            });
            $key = spl_object_id($resource) . ':' . bin2hex(random_bytes(4));
            $this->resources[$key] = $resource;

            return new HttpResponse($response->status, $response->headers, null, $resource);
        }
    }

    public function staging(): InvocationStaging
    {
        $this->assertActive();

        return $this->staging;
    }

    public function grantAsset(string $reference, string $path): void
    {
        $this->assertActive();

        if ($this->assetRoot === null) {
            throw new CapabilityDenied('asset reads are not granted');
        }
        $root = $this->withinRoot($this->assetRoot, 'asset root');
        $real = realpath($path);

        if ($real === false || ! is_file($real) || ! $this->isBelow($real, $root)) {
            throw new CapabilityDenied('asset is outside the granted asset root');
        }
        $this->assets[$reference] = $real;
    }

    public function readAsset(string $reference): ReadableResource
    {
        $this->assertActive();
        $path = $this->assets[$reference] ?? throw new CapabilityDenied('asset is not granted');
        $resource = new FileResource($path, static function (): void {});
        $this->resources[spl_object_id($resource) . ':' . bin2hex(random_bytes(4))] = $resource;

        return $resource;
    }

    /** @param list<mixed> $arguments */
    public function runHelper(string $name, array $arguments = [], float $timeout = 3.0): HelperResult
    {
        $this->assertActive();
        $grant = $this->helpers[$name] ?? throw new CapabilityDenied('helper is not declared');
        $relative = $this->safeRelative($grant->relativeExecutable);
        $package = realpath($this->packageRoot . '/' . $relative);

        if ($package === false || ! $this->isBelow($package, $this->packageRoot) || ! is_file($package)) {
            throw new CapabilityDenied('helper is outside the package');
        }

        foreach ($arguments as $argument) {
            if (! is_string($argument) || str_contains($argument, "\0")) {
                throw new CapabilityDenied('helper argument is invalid');
            }
        }
        $etc = $this->stagingRoot . '/.helper-etc';
        mkdir($etc, 0700, true);
        file_put_contents($etc . '/passwd', "plugin:x:1000:1000:plugin:/tmp:/bin/sh\n");
        file_put_contents($etc . '/group', "plugin:x:1000:\n");
        $command = $this->sandboxPolicy->command($this->packageRoot, $this->stagingRoot, $relative, $etc, null, $grant->network);

        if (! str_ends_with($relative, '.php')) {
            $command[count($command) - 1] = '/plugin/' . $relative;
        }
        $command = array_values($command);
        array_push($command, ...$arguments);
        $result = (new GenericProcessExecutor())->run(new PendingProcess($command, Duration::seconds((int) ceil($timeout))));

        return new HelperResult($result->exitCode, $result->output, $result->errorOutput);
    }

    public function log(string $message): void
    {
        $this->event('log', $this->redact($message));
    }

    public function progress(string $stage): void
    {
        $this->event('progress', $this->redact($stage));
    }

    /** @return list<array{kind:string, value:string}> */
    public function events(): array
    {
        return $this->events;
    }

    public function close(): void
    {
        if (! $this->active) {
            return;
        }
        $this->active = false;

        foreach ($this->resources as $resource) {
            $resource->close();
        }
        $this->resources = [];
        $this->removeTree($this->resourceRoot);
        $this->removeTree($this->stagingRoot . '/.helper-etc');
        $this->removeTree($this->stagingRoot);
    }

    public function cancel(): void
    {
        $this->close();
    }

    public function assertActive(): void
    {
        if (! $this->active) {
            throw new InvocationClosed('invocation has ended');
        }
    }

    private function event(string $kind, string $value): void
    {
        $this->assertActive();
        $this->events[] = ['kind' => $kind, 'value' => $value];
    }

    private function redact(string $value): string
    {
        foreach ($this->credentials as $credential) {
            $value = str_replace($credential->secret, '[REDACTED]', $value);
        }

        return $value;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function safeHeaders(array $headers, ?CredentialGrant $grant, string $origin): array
    {
        $protected = ['authorization', 'proxy-authorization'];

        foreach ($this->credentials as $candidate) {
            if ($candidate->placement === 'header') {
                $protected[] = strtolower($candidate->parameter);
            }
        }
        $safe = [];

        foreach ($headers as $name => $value) {
            if (! in_array(strtolower($name), $protected, true)) {
                $safe[$name] = $value;
            }
        }

        if ($grant !== null && $grant->origin === $origin) {
            if ($grant->placement === 'header') {
                $safe[$grant->parameter] = $grant->secret;
            }
        }

        return $safe;
    }

    private function safeUrl(string $url, ?CredentialGrant $grant, string $origin): string
    {
        if ($grant === null || $grant->placement !== 'query' || $grant->origin !== $origin) {
            return $url;
        }
        $uri = new Uri($url);
        $query = Query::parse($uri->getQuery());
        $query[$grant->parameter] = $grant->secret;

        return (string) $uri->withQuery(Query::build($query));
    }

    private function assertAllowedDestination(string $url): void
    {
        $destination = new Uri($url);

        foreach ($this->allowedPrefixes as $prefix) {
            $grant = new Uri($this->normalizeUrl($prefix));

            if ($grant->getScheme() !== $destination->getScheme() || strtolower($grant->getHost()) !== strtolower($destination->getHost()) || $grant->getPort() !== $destination->getPort()) {
                continue;
            }
            $path = $grant->getPath() === '' ? '/' : $grant->getPath();
            $destinationPath = $destination->getPath() === '' ? '/' : $destination->getPath();

            if ($destinationPath === rtrim($path, '/') || str_starts_with($destinationPath, rtrim($path, '/') . '/')) {
                return;
            }
        }

        throw new CapabilityDenied('HTTP destination is not granted');
    }

    private function origin(string $url): string
    {
        $uri = new Uri($url);

        if (! in_array(strtolower($uri->getScheme()), ['http', 'https'], true) || $uri->getHost() === '') {
            throw new CapabilityDenied('HTTP URL is invalid');
        }

        return strtolower($uri->getScheme() . '://' . $uri->getHost() . ($uri->getPort() === null ? '' : ':' . $uri->getPort()));
    }

    private function normalizeUrl(string $url): string
    {
        $uri = new Uri($url);

        if (! in_array(strtolower($uri->getScheme()), ['http', 'https'], true) || $uri->getHost() === '') {
            throw new CapabilityDenied('HTTP URL is invalid');
        }

        return (string) UriResolver::resolve($uri, new Uri(''));
    }

    private function safeRelative(string $path): string
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new CapabilityDenied('helper path must be relative');
        }
        $parts = explode('/', $path);

        if (in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) {
            throw new CapabilityDenied('helper path is unsafe');
        }

        return $path;
    }

    private function withinRoot(string $path, string $label): string
    {
        $real = realpath($path);

        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException($label . ' does not exist');
        }

        return $real;
    }

    private function isBelow(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/') . '/');
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
