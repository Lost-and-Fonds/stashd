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
use App\System\Secret\SecretsService;
use App\Vault\AssetKind;
use App\Vault\AssetRole;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class ExternalInputPlugin implements Provider, DownloaderInterface
{
    private const string REFRESH = 'plugin.refresh';
    private const string COMPLETE = 'plugin.complete';
    private const string ACQUIRE = 'plugin.acquire';

    public function __construct(
        private ExternalInputPluginDefinition $definition,
        private PluginHostClient $host,
        private SecretsService $secrets,
    ) {
    }

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
        if (! $this->isRuntimeAvailable()) {
            return false;
        }

        foreach ($this->definition->sourcePrefixes as $prefix) {
            if (str_starts_with($uri->toString(), $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function resolveInput(StashdUri $uri): ResolvedInput
    {
        $result = $this->host->resolveInput(
            $this->definition->componentPath,
            $uri->toString(),
            $this->fixtureDirectory(),
            $this->definition->httpGrants($this->secrets, 'resolve'),
        );
        $resolved = $result->resolved ?? throw new ProviderException('Plugin returned no resolved input.');
        $id = self::requiredString($resolved, 'id');
        $reference = self::optionalString($resolved, 'canonical_reference') ?? $uri->toString();
        $kind = self::optionalString($resolved, 'kind') ?? 'input';

        return new ResolvedInput(
            providerKey: $this->key(),
            inputType: $kind,
            sourceUri: StashdUri::parse($reference),
            providerInputId: $id,
            title: self::optionalString($resolved, 'title'),
            sourceTitle: self::optionalString($resolved, 'title'),
            sourceAvatarUri: self::optionalUri($resolved, 'artwork_reference'),
            estimatedItemCount: self::optionalInt($resolved, 'estimated_item_count'),
        );
    }

    public function discoveryStrategies(): array
    {
        return [
            new ProviderStrategy(self::REFRESH, StrategyPurpose::Discovery, StrategyCost::Low, supportsIncremental: true, supportsBackfill: true, priority: 10),
            new ProviderStrategy(self::COMPLETE, StrategyPurpose::Discovery, StrategyCost::Medium, requiresAuth: $this->definition->operationRequiresCredential('complete'), supportsIncremental: false, supportsBackfill: true, priority: 10),
        ];
    }

    public function metadataStrategies(): array
    {
        return [];
    }

    public function downloadStrategies(): array
    {
        return [new ProviderStrategy(self::ACQUIRE, StrategyPurpose::Download, StrategyCost::Medium, priority: 10)];
    }

    /**
     * @param array<string, bool|string> $options
     * @return list<DiscoveredItem>
     */
    public function discover(ResolvedInput $input, ProviderStrategy $strategy, array $options = []): array
    {
        $intent = match ($strategy->key) {
            self::REFRESH => 'refresh',
            self::COMPLETE => 'complete',
            default => throw new ProviderException('Unsupported external discovery operation.', 'unsupported_discovery_operation'),
        };
        $result = $this->host->discoverInput(
            $this->definition->componentPath,
            $input->providerInputId,
            $this->fixtureDirectory(),
            $intent,
            $this->definition->httpGrants($this->secrets, $intent),
            $options,
        );

        return array_map(fn (array $item): DiscoveredItem => $this->mapItem($item), $result->items ?? []);
    }

    public function isStrategyAvailable(ProviderStrategy $strategy): bool
    {
        if (! $this->isRuntimeAvailable()) {
            return false;
        }

        return match ($strategy->key) {
            self::REFRESH => $this->definition->operationAvailable($this->secrets, 'refresh'),
            self::COMPLETE => $this->definition->operationAvailable($this->secrets, 'complete'),
            self::ACQUIRE => $this->definition->operationAvailable($this->secrets, 'acquire'),
            default => false,
        };
    }

    public function inputOptions(ResolvedInput $input): array
    {
        return array_values(array_filter(
            $this->definition->declaredInputOptions(),
            static fn ($option): bool => $option->applicableInputTypes === [] || in_array($input->inputType, $option->applicableInputTypes, true),
        ));
    }

    public function implementationName(): string
    {
        return 'plugin:' . $this->definition->id;
    }

    public function implementationVersion(): string
    {
        return $this->definition->version;
    }

    public function probe(): DownloadProbeResult
    {
        return new DownloadProbeResult(
            available: $this->isRuntimeAvailable() && $this->definition->operationAvailable($this->secrets, 'acquire'),
            implementation: $this->implementationName(),
            implementationVersion: $this->implementationVersion(),
        );
    }

    public function download(DownloadRequest $request, ?callable $onProgress = null): DownloadResult
    {
        $item = [
            'id' => $request->providerItemId,
            'reference' => $request->canonicalUri->toString(),
            'title' => $request->title ?? '',
            'description' => null,
            'published_at' => $request->publishedAt?->toRfc3339(useZ: true),
            'artwork_reference' => $request->thumbnailUri?->toString(),
            'duration_seconds' => $request->durationSeconds,
            'kind' => null,
        ];
        $files = $this->acquireArtifacts(
            item: $item,
            staging: $request->tempDirectory,
            mediaKind: $request->downloadPolicy->value === 'audio_only' ? 'audio' : 'video',
            options: $request->providerOptions,
        );
        if (! array_filter($files, static fn (DownloadedFile $file): bool => $file->role === AssetRole::VaultOriginal)) {
            throw DownloadException::withCode('plugin_missing_primary', 'External Input plugin returned no primary artifact.');
        }

        return new DownloadResult(
            files: $files,
            implementation: $this->implementationName(),
            implementationVersion: $this->implementationVersion(),
            sourceUri: $request->canonicalUri,
            attemptedAt: DateTime::now(Timezone::UTC),
            provenance: [
                'plugin_id' => $this->definition->id,
                'provider_key' => $this->key(),
                'plugin_version' => $this->definition->version,
            ],
        );
    }

    /**
     * Acquire generic staged artifacts for auxiliary application workflows.
     * The caller interprets only generic artifact roles; plugin options remain opaque.
     *
     * @param array<string, mixed> $item
     * @param array<string, bool|string> $options
     * @return list<DownloadedFile>
     */
    public function acquireArtifacts(array $item, string $staging, string $mediaKind, array $options = []): array
    {
        $helper = $this->definition->helperExecutable();
        $result = $this->host->acquireInput(
            $this->definition->componentPath,
            $item,
            $staging,
            $helper !== null
                ? new PluginHelperGrant($this->definition->helperName ?? $helper, $helper)
                : null,
            $mediaKind,
            $options,
        );
        $artifacts = $result->acquisition['artifacts'] ?? [];
        if (! is_array($artifacts)) {
            throw DownloadException::withCode('plugin_invalid_acquisition', 'External Input plugin returned invalid artifacts.');
        }

        $files = [];
        foreach ($artifacts as $artifact) {
            if (! is_array($artifact)) {
                throw DownloadException::withCode('plugin_invalid_acquisition', 'External Input plugin returned an invalid artifact.');
            }
            $artifactMap = [];
            foreach ($artifact as $key => $value) {
                if (is_string($key)) {
                    $artifactMap[$key] = $value;
                }
            }
            $files[] = $this->mapArtifact($staging, $artifactMap);
        }

        return $files;
    }

    public function isRuntimeAvailable(): bool
    {
        return $this->definition->componentPath !== '' && is_file($this->definition->componentPath);
    }

    /** @param array<string, mixed> $item */
    private function mapItem(array $item): DiscoveredItem
    {
        return new DiscoveredItem(
            providerItemId: self::requiredString($item, 'id'),
            canonicalUri: StashdUri::parse(self::requiredString($item, 'reference')),
            title: self::requiredString($item, 'title'),
            description: self::optionalString($item, 'description'),
            durationSeconds: self::optionalInt($item, 'duration_seconds'),
            publishedAt: ProviderDates::tryParse(self::optionalString($item, 'published_at')),
            thumbnailUri: self::optionalUri($item, 'artwork_reference'),
            contentType: self::optionalString($item, 'kind'),
        );
    }

    /** @param array<string, mixed> $artifact */
    private function mapArtifact(string $staging, array $artifact): DownloadedFile
    {
        $reference = self::requiredString($artifact, 'reference');
        if ($reference === '' || str_starts_with($reference, '/') || str_contains($reference, '..')) {
            throw DownloadException::withCode('plugin_invalid_artifact_reference', 'External Input plugin returned an unsafe artifact reference.');
        }
        $path = rtrim($staging, '/') . '/' . $reference;
        if (! is_file($path) || ! is_readable($path)) {
            throw DownloadException::withCode('plugin_missing_artifact', 'External Input plugin artifact is missing from staging.');
        }

        $role = match (self::requiredString($artifact, 'role')) {
            'primary' => AssetRole::VaultOriginal,
            'captions' => AssetRole::Subtitle,
            'artwork' => AssetRole::SourceThumbnail,
            'metadata' => AssetRole::MetadataJson,
            default => throw DownloadException::withCode('plugin_invalid_artifact_role', 'External Input plugin returned an unknown artifact role.'),
        };
        $mediaType = self::optionalString($artifact, 'media_type');

        return new DownloadedFile(
            tempPath: $path,
            filename: basename($reference),
            role: $role,
            kind: self::assetKind($mediaType),
            mimeType: $mediaType,
            container: pathinfo($reference, PATHINFO_EXTENSION) ?: null,
            sizeBytes: filesize($path) ?: 0,
        );
    }

    private function fixtureDirectory(): ?string
    {
        $value = getenv('STASHD_PLUGIN_FIXTURE_DIR');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function assetKind(?string $mediaType): AssetKind
    {
        if ($mediaType === null) {
            return AssetKind::Other;
        }

        return match (true) {
            str_starts_with($mediaType, 'video/') => AssetKind::Video,
            str_starts_with($mediaType, 'audio/') => AssetKind::Audio,
            str_starts_with($mediaType, 'image/') => AssetKind::Image,
            str_starts_with($mediaType, 'text/') => AssetKind::Subtitle,
            $mediaType === 'application/json' => AssetKind::Metadata,
            default => AssetKind::Other,
        };
    }

    /** @param array<string, mixed> $values */
    private static function requiredString(array $values, string $field): string
    {
        $value = $values[$field] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new ProviderException("Plugin returned an invalid {$field}.", 'invalid_plugin_result');
        }

        return trim($value);
    }

    /** @param array<string, mixed> $values */
    private static function optionalString(array $values, string $field): ?string
    {
        $value = $values[$field] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @param array<string, mixed> $values */
    private static function optionalInt(array $values, string $field): ?int
    {
        $value = $values[$field] ?? null;

        return is_int($value) ? $value : null;
    }

    /** @param array<string, mixed> $values */
    private static function optionalUri(array $values, string $field): ?StashdUri
    {
        $value = self::optionalString($values, $field);

        return $value !== null ? StashdUri::parse($value) : null;
    }
}
