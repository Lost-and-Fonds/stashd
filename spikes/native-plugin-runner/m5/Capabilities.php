<?php

declare(strict_types=1);

namespace Stashd\NativeCapabilities;

use Stashd\PluginSdk\CapabilityUnavailableException;
use Stashd\PluginSdk\HttpClient;
use Stashd\PluginSdk\HttpResponse;
use Stashd\PluginSdk\ReadableResource;
use Stashd\PluginSdk\StagedArtifact;
use Stashd\PluginSdk\StagingArea;
use RuntimeException;

final class CapabilityDenied extends RuntimeException {}
final class InvocationClosed extends RuntimeException {}
final class UnsafePath extends RuntimeException {}

final readonly class TransportResponse
{
    /** @param array<string, string> $headers @param iterable<string> $chunks */
    public function __construct(public int $status, public array $headers, public iterable $chunks) {}
}

interface HostHttpTransport
{
    /** @param array<string, string> $headers */
    public function request(string $method, string $url, array $headers, ?string $body): TransportResponse;
}

final readonly class CredentialGrant
{
    public function __construct(public string $reference, public string $origin, public string $header, public string $secret) {}
}

final readonly class HelperGrant
{
    public function __construct(public string $name, public string $relativeExecutable) {}
}

final readonly class HelperResult
{
    public function __construct(public int $exitCode, public string $stdout, public string $stderr) {}
}

final readonly class PublishedOutput
{
    public function __construct(public string $reference, public string $relativePath, public int $sizeBytes, public ?string $mediaType) {}
}

final class FileResource implements ReadableResource
{
    /** @var resource|null */
    private $handle;
    private bool $closed = false;

    /** @param callable():void $onClose */
    public function __construct(private string $path, private $onClose)
    {
        $this->handle = fopen($path, 'rb');
        if ($this->handle === false) {
            throw new RuntimeException('resource could not be opened');
        }
    }

    public function read(int $maximumBytes = 65536): string
    {
        if ($this->closed) {
            throw new InvocationClosed('resource is closed');
        }
        return (string) fread($this->handle, max(1, $maximumBytes));
    }

    public function isEof(): bool { return $this->closed || feof($this->handle); }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        fclose($this->handle);
        $this->closed = true;
        ($this->onClose)();
    }
}

final class BrokerHttpClient implements HttpClient
{
    public function __construct(private Invocation $invocation) {}

    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?string $credential = null): HttpResponse
    {
        return $this->invocation->http($method, $url, $headers, $body, $credential);
    }
}

final class InvocationStaging implements StagingArea
{
    public function __construct(private Invocation $invocation, private string $root) {}

    public function write(string $relativePath, string $content, ?string $mediaType = null): StagedArtifact
    {
        $path = $this->safePath($relativePath, false);
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new UnsafePath('staging output already exists or cannot be created');
        }
        try {
            fwrite($handle, $content);
        } finally {
            fclose($handle);
        }
        return $this->stage($relativePath, $mediaType);
    }

    public function stage(string $relativePath, ?string $mediaType = null): StagedArtifact
    {
        $path = $this->safePath($relativePath, true);
        return new StagedArtifact('staging:' . hash('sha256', $relativePath), $mediaType ?? 'application/octet-stream', (int) filesize($path));
    }

    public function output(string $relativePath, ?string $mediaType = null): PublishedOutput
    {
        $path = $this->safePath($relativePath, true);
        return new PublishedOutput('staging:' . hash('sha256', $relativePath), $relativePath, (int) filesize($path), $mediaType);
    }

    private function safePath(string $relativePath, bool $mustExist): string
    {
        if ($relativePath === '' || str_starts_with($relativePath, '/') || str_contains($relativePath, "\0")) {
            throw new UnsafePath('staging path must be relative');
        }
        $parts = explode('/', $relativePath);
        if (in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) {
            throw new UnsafePath('staging path contains an unsafe segment');
        }
        $cursor = $this->root;
        $last = array_pop($parts);
        foreach ($parts as $part) {
            $cursor .= '/' . $part;
            if (is_link($cursor)) {
                throw new UnsafePath('staging path crosses a symlink');
            }
            if (file_exists($cursor) && !is_dir($cursor)) {
                throw new UnsafePath('staging path crosses a file');
            }
            if (!is_dir($cursor) && !mkdir($cursor, 0700, true) && !is_dir($cursor)) {
                throw new UnsafePath('staging directory could not be created');
            }
        }
        $path = $cursor . '/' . $last;
        if (is_link($path)) {
            throw new UnsafePath('staging target is a symlink');
        }
        if ($mustExist && !is_file($path)) {
            throw new UnsafePath('staging output does not exist');
        }
        return $path;
    }
}

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

    /** @param list<string> $allowedOrigins @param list<CredentialGrant> $credentials @param list<HelperGrant> $helpers */
    public function __construct(
        private string $packageRoot,
        private string $stagingRoot,
        private array $allowedOrigins,
        array $credentials = [],
        private ?string $assetRoot = null,
        array $helpers = [],
        private HostHttpTransport $transport = new FixtureTransport(),
        private int $inlineLimit = 65536,
        private int $maxRedirects = 3,
    ) {
        $this->packageRoot = $this->withinRoot($packageRoot, 'package');
        if (!is_dir($stagingRoot) && !mkdir($stagingRoot, 0700, true) && !is_dir($stagingRoot)) {
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
        $this->staging = new InvocationStaging($this, $this->stagingRoot);
    }

    public function http(string $method, string $url, array $headers = [], ?string $body = null, ?string $credential = null): HttpResponse
    {
        $this->assertActive();
        $current = $url;
        $grant = $credential === null ? null : ($this->credentials[$credential] ?? throw new CapabilityDenied('credential is not granted'));
        for ($redirect = 0; ; $redirect++) {
            $origin = $this->origin($current);
            $this->assertAllowedOrigin($origin);
            $requestHeaders = $this->safeHeaders($headers, $grant, $origin);
            $response = $this->transport->request(strtoupper($method), $current, $requestHeaders, $body);
            $location = $response->headers['Location'] ?? $response->headers['location'] ?? null;
            if ($location !== null && $response->status >= 300 && $response->status < 400) {
                if ($redirect >= $this->maxRedirects) {
                    throw new CapabilityDenied('redirect limit exceeded');
                }
                $current = $this->resolveUrl($current, $location);
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

    public function staging(): InvocationStaging { $this->assertActive(); return $this->staging; }

    public function grantAsset(string $reference, string $path): void
    {
        $this->assertActive();
        if ($this->assetRoot === null) {
            throw new CapabilityDenied('asset reads are not granted');
        }
        $root = $this->withinRoot($this->assetRoot, 'asset root');
        $real = realpath($path);
        if ($real === false || !is_file($real) || !$this->isBelow($real, $root)) {
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

    public function runHelper(string $name, array $arguments = [], float $timeout = 3.0): HelperResult
    {
        $this->assertActive();
        $grant = $this->helpers[$name] ?? throw new CapabilityDenied('helper is not declared');
        $relative = $this->safeRelative($grant->relativeExecutable);
        $package = realpath($this->packageRoot . '/' . $relative);
        if ($package === false || !$this->isBelow($package, $this->packageRoot) || !is_file($package)) {
            throw new CapabilityDenied('helper is outside the package');
        }
        foreach ($arguments as $argument) {
            if (!is_string($argument) || str_contains($argument, "\0")) {
                throw new CapabilityDenied('helper argument is invalid');
            }
        }
        $etc = $this->stagingRoot . '/.helper-etc';
        mkdir($etc, 0700, true);
        file_put_contents($etc . '/passwd', "plugin:x:1000:1000:plugin:/tmp:/bin/sh\n");
        file_put_contents($etc . '/group', "plugin:x:1000:\n");
        $command = ['bwrap', '--die-with-parent', '--new-session', '--unshare-user', '--unshare-pid', '--unshare-ipc', '--unshare-uts', '--unshare-net', '--clearenv',
            '--ro-bind', $this->packageRoot, '/plugin', '--bind', $this->stagingRoot, '/staging', '--tmpfs', '/tmp', '--dev', '/dev',
            '--ro-bind', '/usr', '/usr', '--ro-bind', '/bin', '/bin', '--ro-bind', '/lib', '/lib', '--ro-bind', '/lib64', '/lib64', '--ro-bind', '/sbin', '/sbin',
            '--ro-bind', $etc, '/etc', '--dir', '/home', '--dir', '/root', '--dir', '/run', '--chdir', '/plugin', '--setenv', 'HOME', '/tmp', '--setenv', 'PATH', '/usr/local/bin:/usr/bin:/bin', '--'];
        $command[] = str_ends_with($relative, '.php') ? 'php' : '/plugin/' . $relative;
        if (str_ends_with($relative, '.php')) {
            $command[] = '/plugin/' . $relative;
        }
        array_push($command, ...$arguments);
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('helper could not start: ' . implode(' ', $command));
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeout;
        do {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                throw new RuntimeException('helper timed out');
            }
            usleep(10_000);
        } while (true);
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $exit = proc_close($process);
        return new HelperResult($exit, $stdout, $stderr);
    }

    public function log(string $message): void { $this->event('log', $this->redact($message)); }
    public function progress(string $stage): void { $this->event('progress', $this->redact($stage)); }
    /** @return list<array{kind:string, value:string}> */
    public function events(): array { return $this->events; }
    public function close(): void
    {
        if (!$this->active) {
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
    public function cancel(): void { $this->close(); }
    public function assertActive(): void { if (!$this->active) { throw new InvocationClosed('invocation has ended'); } }

    private function event(string $kind, string $value): void { $this->assertActive(); $this->events[] = ['kind' => $kind, 'value' => $value]; }
    private function redact(string $value): string { foreach ($this->credentials as $credential) { $value = str_replace($credential->secret, '[REDACTED]', $value); } return $value; }
    private function safeHeaders(array $headers, ?CredentialGrant $grant, string $origin): array
    {
        $protected = ['authorization', 'proxy-authorization'];
        foreach ($this->credentials as $candidate) { $protected[] = strtolower($candidate->header); }
        $safe = [];
        foreach ($headers as $name => $value) { if (!in_array(strtolower($name), $protected, true)) { $safe[$name] = $value; } }
        if ($grant !== null && $grant->origin === $origin) { $safe[$grant->header] = $grant->secret; }
        return $safe;
    }
    private function assertAllowedOrigin(string $origin): void { if (!in_array($origin, $this->allowedOrigins, true)) { throw new CapabilityDenied('HTTP destination is not granted'); } }
    private function origin(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) { throw new CapabilityDenied('HTTP URL is invalid'); }
        return strtolower($parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
    }
    private function resolveUrl(string $base, string $location): string
    {
        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) { return $location; }
        $parts = parse_url($base);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) { throw new CapabilityDenied('redirect URL is invalid'); }
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/' . ltrim($location, '/');
    }
    private function safeRelative(string $path): string
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")) { throw new CapabilityDenied('helper path must be relative'); }
        $parts = explode('/', $path);
        if (in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) { throw new CapabilityDenied('helper path is unsafe'); }
        return $path;
    }
    private function withinRoot(string $path, string $label): string
    {
        $real = realpath($path);
        if ($real === false || !is_dir($real)) { throw new RuntimeException($label . ' does not exist'); }
        return $real;
    }
    private function isBelow(string $path, string $root): bool { return $path === $root || str_starts_with($path, rtrim($root, '/') . '/'); }
    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        if (!is_dir($path)) { return; }
        foreach (scandir($path) ?: [] as $entry) { if ($entry !== '.' && $entry !== '..') { $this->removeTree($path . '/' . $entry); } }
        @rmdir($path);
    }
}

final class FixtureTransport implements HostHttpTransport
{
    /** @var callable(string, string, array<string, string>, ?string):TransportResponse|null */
    private $handler;
    public function __construct(?callable $handler = null) { $this->handler = $handler; }
    public function request(string $method, string $url, array $headers, ?string $body): TransportResponse
    {
        if ($this->handler !== null) { return ($this->handler)($method, $url, $headers, $body); }
        return new TransportResponse(200, ['content-type' => 'text/plain'], ['fixture']);
    }
}
