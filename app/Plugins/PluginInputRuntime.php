<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Downloads\DownloadedFile;
use App\Downloads\DownloaderInterface;
use App\Downloads\DownloadException;
use App\Downloads\DownloadProbeResult;
use App\Downloads\DownloadRequest;
use App\Downloads\DownloadResult;
use App\Providers\Core\DiscoveredItem;
use App\Providers\Provider;
use App\Providers\ProviderDates;
use App\Providers\ProviderException;
use App\Providers\ProviderStrategy;
use App\Providers\ResolvedInput;
use App\Providers\StashdUri;
use App\Providers\StrategyCost;
use App\Providers\StrategyPurpose;
use App\Stashes\DownloadPolicy;
use App\System\Secret\SecretsService;
use App\Vault\AssetKind;
use App\Vault\AssetRole;
use GuzzleHttp\Psr7\Uri;
use RuntimeException;
use Stashd\PluginRuntime\Capabilities\CredentialGrant;
use Stashd\PluginRuntime\Capabilities\Invocation;
use Stashd\PluginRuntime\Package\PackageManager;
use Stashd\PluginRuntime\Runner\PluginRunner;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;
use Tempest\Support\Filesystem;

final readonly class PluginInputRuntime implements Provider, DownloaderInterface
{
    public function __construct(private PluginInputDefinition $definition, private PluginRunner $runner, private PackageManager $packages, private SecretsService $secrets) {}
    public function key(): string
    {
        return $this->definition->providerKey;
    }
    public function name(): string
    {
        return $this->definition->name;
    }
    public function supportsUri(StashdUri $uri): bool
    {
        foreach ($this->definition->prefixes as $prefix) {
            if (str_starts_with($uri->toString(), $prefix)) {
                return true;
            }
        }

        return false;
    }
    public function resolveInput(StashdUri $uri): ResolvedInput
    {
        $result = $this->invoke('input.resolve', ['source' => $uri->toString()], 'resolve');
        $resolved = $result;
        $reference = is_string($resolved['canonical-reference'] ?? null) ? $resolved['canonical-reference'] : $uri->toString();

        return new ResolvedInput($this->key(), (string) ($resolved['kind'] ?? 'input'), StashdUri::parse($reference), (string) ($resolved['id'] ?? ''), $resolved['title'] ?? null, $resolved['title'] ?? null, isset($resolved['artwork-reference']) ? StashdUri::parse($resolved['artwork-reference']) : null, isset($resolved['estimated-item-count']) ? (int) $resolved['estimated-item-count'] : null);
    }
    public function discoveryStrategies(): array
    {
        return [new ProviderStrategy('plugin.refresh', StrategyPurpose::Discovery, StrategyCost::Low, supportsIncremental: true, supportsBackfill: true, priority: 10), new ProviderStrategy('plugin.complete', StrategyPurpose::Discovery, StrategyCost::Medium, requiresAuth: true, supportsBackfill: true, priority: 10)];
    }
    public function metadataStrategies(): array
    {
        return [];
    }
    public function downloadStrategies(): array
    {
        return [new ProviderStrategy('plugin.acquire', StrategyPurpose::Download, StrategyCost::Medium, priority: 10)];
    }
    public function isStrategyAvailable(ProviderStrategy $strategy): bool
    {
        return $strategy->key !== 'plugin.complete' || $this->definition->httpGrants($this->secrets, 'complete') !== [];
    }
    public function inputOptions(ResolvedInput $input): array
    {
        return $this->definition->options;
    }
    public function discover(ResolvedInput $input, ProviderStrategy $strategy, array $options = []): array
    {
        $raw = $this->invoke('input.discover', ['input_id' => $input->providerInputId, 'intent' => $strategy->key === 'plugin.complete' ? 'complete' : 'refresh', 'options' => $this->wireOptions($options)], $strategy->key === 'plugin.complete' ? 'complete' : 'refresh');

        return array_map(static fn(array $item): DiscoveredItem => new DiscoveredItem((string) $item['id'], StashdUri::parse((string) $item['reference']), (string) $item['title'], $item['description'] ?? null, isset($item['duration-seconds']) ? (int) $item['duration-seconds'] : null, ProviderDates::tryParse($item['published-at'] ?? null), isset($item['artwork-reference']) ? StashdUri::parse($item['artwork-reference']) : null, null, $item['kind'] ?? null), array_values(array_filter($raw, 'is_array')));
    }
    public function implementationName(): string
    {
        return 'plugin:' . $this->definition->id;
    }
    public function implementationVersion(): ?string
    {
        return $this->definition->version;
    }
    public function probe(): DownloadProbeResult
    {
        return new DownloadProbeResult($this->definition->helper !== null, $this->implementationName(), $this->implementationVersion());
    }
    public function download(DownloadRequest $request, ?callable $onProgress = null): DownloadResult
    {
        $item = ['id' => $request->providerItemId, 'reference' => $request->canonicalUri->toString(), 'title' => $request->title, 'description' => null, 'published-at' => $request->publishedAt?->toRfc3339(useZ: true), 'artwork-reference' => $request->thumbnailUri?->toString(), 'duration-seconds' => $request->durationSeconds];
        $kind = $request->downloadPolicy === DownloadPolicy::AudioOnly ? 'audio' : 'video';
        $result = $this->invoke('input.acquire', ['item' => $item, 'media_kind' => $kind, 'options' => $this->wireOptions($request->providerOptions)], 'acquire', $request->tempDirectory, $this->definition->helper);
        $files = [];

        foreach (is_array($result['artifacts'] ?? null) ? $result['artifacts'] : [] as $artifact) {
            if (! is_array($artifact)) {
                continue;
            }
            $reference = (string) ($artifact['reference'] ?? '');
            $path = rtrim($request->tempDirectory, '/') . '/' . $reference;

            if ($reference === '' || str_contains($reference, '..') || ! Filesystem\is_file($path)) {
                continue;
            }
            $mime = is_string($artifact['media-type'] ?? null) ? $artifact['media-type'] : null;
            $role = match ($artifact['role'] ?? null) {
                'primary' => AssetRole::VaultOriginal, 'captions' => AssetRole::Subtitle, 'artwork' => AssetRole::SourceThumbnail, 'metadata' => AssetRole::MetadataJson, default => null,
            };

            if ($role === null) {
                continue;
            }
            $files[] = new DownloadedFile($path, basename($reference), $role, $this->assetKind($mime), $mime, pathinfo($reference, PATHINFO_EXTENSION), filesize($path) ?: 0);
        }

        if (! array_filter($files, static fn(DownloadedFile $file): bool => $file->role === AssetRole::VaultOriginal)) {
            throw DownloadException::withCode('plugin_missing_primary', 'YouTube acquisition produced no primary artifact.');
        }

        return new DownloadResult($files, $this->implementationName(), $this->implementationVersion(), $request->canonicalUri, DateTime::now(Timezone::UTC), ['plugin_id' => $this->definition->id]);
    }
    private function invoke(string $method, array $params, string $operation, ?string $staging = null, ?PluginHelperGrant $helper = null): array
    {
        $package = $this->packages->activePath($this->definition->id) ?? throw new RuntimeException('YouTube plugin is not active');
        $stage = $staging === null ? sys_get_temp_dir() . '/stashd-plugin-' . bin2hex(random_bytes(5)) : $staging . '/.plugin-' . bin2hex(random_bytes(5));

        if (! mkdir($stage, 0700, true) && ! is_dir($stage)) {
            throw new RuntimeException('plugin staging could not be created');
        }
        $prefixes = [];
        $credentials = [];

        foreach ($this->definition->httpGrants($this->secrets, $operation) as $grant) {
            foreach ($grant->allowedPrefixes as $prefix) {
                $uri = new Uri($prefix);

                if ($uri->getScheme() === '' || $uri->getHost() === '') {
                    continue;
                }
                $prefixes[] = $prefix;
                $origin = strtolower($uri->getScheme() . '://' . $uri->getHost() . ($uri->getPort() === null ? '' : ':' . $uri->getPort()));

                if ($grant->credential !== null) {
                    $credentials[] = new CredentialGrant($grant->credential->name, $origin, $grant->credential->parameter, $grant->credential->value, $grant->credential->placement);
                }
            }
        }
        $invocation = new Invocation($package, $stage, array_values(array_unique($prefixes)), $credentials, helpers: $helper === null ? [] : [new \Stashd\PluginRuntime\Capabilities\HelperGrant($helper->name, substr($helper->executable, strlen($package) + 1), $helper->network)], transport: new PluginBroadcastHttpTransport());
        $process = $this->runner->start($this->definition->id, $stage);

        try {
            $result = $process->invoke($method, $params, function (array $message) use ($invocation): array {
                $p = is_array($message['params'] ?? null) ? $message['params'] : [];

                return match ($message['method'] ?? '') {
                    'http.request' => $this->capabilityHttp($invocation, $p), 'staging.stage' => $this->capabilityStage($invocation, $p), 'staging.write' => $this->capabilityWrite($invocation, $p), 'helper.run' => $this->capabilityHelper($invocation, $p), 'event.log', 'event.progress' => ['accepted' => true], default => throw new RuntimeException('unsupported plugin capability'),
                };
            });

            if (isset($result['error'])) {
                throw new RuntimeException((string) (($result['error']['message'] ?? null) ?: 'plugin failed'));
            }

            if ($staging !== null) {
                $this->copy($stage, $staging);
            }

            return $result;
        } finally {
            $process->close();
            $invocation->close();

            if ($staging === null) {
                $this->remove($stage);
            }
        }
    }
    private function capabilityHttp(Invocation $i, array $p): array
    {
        $r = $i->http((string) ($p['method'] ?? 'GET'), (string) ($p['url'] ?? ''), is_array($p['headers'] ?? null) ? $p['headers'] : [], isset($p['body']) ? (string) $p['body'] : null, isset($p['credential']) ? (string) $p['credential'] : null);

        return ['status' => $r->status, 'headers' => $r->headers, 'body' => $r->body()];
    }
    private function capabilityStage(Invocation $i, array $p): array
    {
        $a = $i->staging()->stage((string) ($p['relative_path'] ?? ''), $p['media_type'] ?? null);

        return ['reference' => $a->reference, 'media_type' => $a->mediaType, 'size_bytes' => $a->sizeBytes];
    }
    private function capabilityWrite(Invocation $i, array $p): array
    {
        $a = $i->staging()->write((string) ($p['relative_path'] ?? ''), base64_decode((string) ($p['content'] ?? ''), true) ?: '', $p['media_type'] ?? null);

        return ['reference' => $a->reference, 'media_type' => $a->mediaType, 'size_bytes' => $a->sizeBytes];
    }
    private function capabilityHelper(Invocation $i, array $p): array
    {
        $r = $i->runHelper((string) ($p['name'] ?? ''), is_array($p['arguments'] ?? null) ? $p['arguments'] : []);

        return ['exit_code' => $r->exitCode, 'stdout' => $r->stdout, 'stderr' => $r->stderr];
    }
    private function copy(string $from, string $to): void
    {
        foreach (scandir($from) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') {
                $source = $from . '/' . $file;
                $target = $to . '/' . $file;

                if (Filesystem\is_file($source)) {
                    rename($source, $target);
                }
            }
        }
    }
    private function remove(string $path): void
    {
        foreach (scandir($path) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') {
                $p = $path . '/' . $file;
                is_dir($p) ? $this->remove($p) : @unlink($p);
            }
        } @rmdir($path);
    }
    private function wireOptions(array $options): array
    {
        return array_map(static fn($key, $value): array => ['key' => $key, 'value' => is_bool($value) ? ['tag' => 'boolean', 'value' => $value] : ['tag' => 'text', 'value' => (string) $value]], array_keys($options), array_values($options));
    }
    private function assetKind(?string $mime): AssetKind
    {
        return match (true) {
            is_string($mime) && str_starts_with($mime, 'video/') => AssetKind::Video, is_string($mime) && str_starts_with($mime, 'audio/') => AssetKind::Audio, is_string($mime) && str_starts_with($mime, 'image/') => AssetKind::Image, is_string($mime) && str_starts_with($mime, 'text/') => AssetKind::Subtitle, $mime === 'application/json' => AssetKind::Metadata, default => AssetKind::Other,
        };
    }
}
