<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\Config\StashdConfig;
use App\Support\PrefixedUlid;
use App\System\Secret\SecretRepository;
use App\System\Secret\SecretsService;
use App\System\Secret\SecretType;
use App\Vault\AssetId;
use App\Vault\AssetRecord;
use RuntimeException;
use SensitiveParameter;
use Tempest\Support\Filesystem;

final readonly class PublishedResourceService
{
    public function __construct(
        private PublishedResourceRepository $resources,
        private BroadcastPathBuilder $paths,
        private StashdConfig $config,
        private SecretsService $secrets,
        private SecretRepository $secretRecords,
        private PublicationCredentialDigest $digests,
    ) {}

    public function publishAsset(
        BroadcastRecord $broadcast,
        AssetRecord $asset,
        string $mediaType,
        string $access = 'public',
        ?string $downloadName = null,
    ): PublishedResourceRecord {
        $existing = $this->resources->findByBroadcastAndAsset(
            BroadcastId::fromPrimaryKey($broadcast->id),
            (string) $asset->id,
            $mediaType,
        );

        if ($existing !== null) {
            $existing->downloadName = $downloadName;
            $existing->access = $access;
            $existing->state = 'ready';

            $this->ensureCredential($existing);

            return $this->resources->save($existing);
        }

        return $this->create($broadcast, $mediaType, $access, $downloadName, assetId: (string) $asset->id);
    }

    public function publishFile(
        BroadcastRecord $broadcast,
        string $relativePath,
        string $mediaType,
        string $access = 'public',
        ?string $downloadName = null,
    ): PublishedResourceRecord {
        $this->paths->broadcastFile($broadcast, ...$this->safeSegments($relativePath));

        $existing = $this->resources->findByBroadcastAndPath(BroadcastId::fromPrimaryKey($broadcast->id), $relativePath);

        if ($existing !== null) {
            $existing->mediaType = $mediaType;
            $existing->downloadName = $downloadName;
            $existing->access = $access;
            $existing->state = 'ready';

            $this->ensureCredential($existing);

            return $this->resources->save($existing);
        }

        return $this->create($broadcast, $mediaType, $access, $downloadName, relativePath: $relativePath);
    }

    public function url(PublishedResourceRecord $resource, ?string $credential = null): string
    {
        $url = rtrim($this->config->publicUrl, '/') . '/published/' . rawurlencode((string) $resource->id);

        if ($resource->access === 'credential') {
            $credential ??= $this->credentialFor($resource);

            if ($credential === null) {
                throw new RuntimeException('Published resource credential is unavailable.');
            }

            $url .= '/access/' . rawurlencode($credential);
        }

        return $url;
    }

    public function credential(PublishedResourceRecord $resource): ?string
    {
        return $resource->access === 'credential' ? $this->credentialFor($resource) : null;
    }

    public function rotateCredential(PublishedResourceRecord $resource): string
    {
        if ($resource->access !== 'credential') {
            throw new RuntimeException('Published resource does not use a credential.');
        }

        if ($resource->credentialSecretId !== null) {
            $old = $this->secretRecords->find(PrefixedUlid::parse($resource->credentialSecretId));

            if ($old !== null) {
                $this->secrets->revoke($old->key);
            }
        }

        $credential = $this->createCredential($resource);

        return $credential;
    }

    public function revokeForBroadcast(BroadcastId $broadcastId): void
    {
        foreach ($this->resources->listForBroadcast($broadcastId) as $resource) {
            if ($resource->credentialSecretId === null) {
                continue;
            }

            $secret = $this->secretRecords->find(PrefixedUlid::parse($resource->credentialSecretId));

            if ($secret !== null) {
                $this->secrets->revoke($secret->key);
            }
        }
    }

    public function authorize(PublishedResourceRecord $resource, #[SensitiveParameter] ?string $credential): bool
    {
        if ($resource->state !== 'ready') {
            return false;
        }

        if ($resource->access === 'public') {
            return true;
        }

        if ($credential === null || $resource->credentialSecretId === null) {
            return false;
        }

        $secret = $this->secretRecords->find(PrefixedUlid::parse($resource->credentialSecretId));

        if ($secret === null || $secret->revokedAt !== null) {
            return false;
        }

        $stored = $this->secrets->get($secret->key);

        return $stored !== null && hash_equals($stored, $credential);
    }

    /** @return array{path: string, media_type: string, size: int, download_name: ?string} */
    public function source(PublishedResourceRecord $resource): array
    {
        $path = null;

        if ($resource->assetId !== null) {
            $asset = AssetRecord::findById($resource->assetId->toPrimaryKey());
            $path = $asset instanceof AssetRecord ? $asset->path : null;
        } elseif ($resource->relativePath !== null) {
            $broadcast = BroadcastRecord::findById($resource->broadcastId->toPrimaryKey());

            if (! $broadcast instanceof BroadcastRecord) {
                throw new RuntimeException('Published resource broadcast is unavailable.');
            }

            $path = $this->paths->broadcastFile(
                $broadcast,
                ...$this->safeSegments($resource->relativePath),
            );
        }

        if ($path === null || ! Filesystem\is_file($path) || ! Filesystem\is_readable($path)) {
            throw new RuntimeException('Published resource is unavailable.');
        }

        $root = rtrim($this->config->mediaPath, '/') . '/';
        $resolved = realpath($path);
        $resolvedRoot = realpath($this->config->mediaPath);

        if ($resolved === false || $resolvedRoot === false || ! str_starts_with($resolved, $resolvedRoot . '/')) {
            throw new RuntimeException('Published resource is outside managed storage.');
        }

        return [
            'path' => $resolved,
            'media_type' => $resource->mediaType,
            'size' => filesize($resolved) ?: 0,
            'download_name' => $resource->downloadName,
        ];
    }

    private function create(
        BroadcastRecord $broadcast,
        string $mediaType,
        string $access,
        ?string $downloadName,
        ?string $assetId = null,
        ?string $relativePath = null,
    ): PublishedResourceRecord {
        if (! in_array($access, ['public', 'credential'], true)) {
            throw new RuntimeException('Unsupported publication access policy.');
        }

        $resource = $this->resources->create(new PublishedResourceRecord(
            broadcastId: BroadcastId::fromPrimaryKey($broadcast->id),
            assetId: $assetId === null ? null : AssetId::parse($assetId),
            relativePath: $relativePath,
            mediaType: $mediaType,
            downloadName: $downloadName,
            access: $access,
        ));

        if ($access === 'credential') {
            $this->createCredential($resource);
        }

        return $resource;
    }

    private function createCredential(PublishedResourceRecord $resource): string
    {
        $credential = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $key = 'broadcast:publication:' . (string) $resource->id . ':' . bin2hex(random_bytes(6));
        $this->secrets->put($key, SecretType::BroadcastToken, $credential, ['publication_id' => (string) $resource->id]);
        $secret = $this->secretRecords->findByKey($key) ?? throw new RuntimeException('Publication credential was not persisted.');
        $secret->tokenDigest = $this->digests->for($credential);
        $this->secretRecords->save($secret);
        $resource->credentialSecretId = (string) $secret->id;
        $this->resources->save($resource);

        return $credential;
    }

    /** @return list<string> */
    private function safeSegments(string $relativePath): array
    {
        if ($relativePath === '' || str_starts_with($relativePath, '/') || str_contains($relativePath, "\0")) {
            throw new RuntimeException('Published resource path is invalid.');
        }

        $segments = explode('/', $relativePath);

        if (in_array('..', $segments, true) || in_array('', $segments, true)) {
            throw new RuntimeException('Published resource path is invalid.');
        }

        return $segments;
    }

    private function credentialFor(PublishedResourceRecord $resource): ?string
    {
        if ($resource->credentialSecretId === null) {
            return null;
        }

        $secret = $this->secretRecords->find(PrefixedUlid::parse($resource->credentialSecretId));

        return $secret === null || $secret->revokedAt !== null ? null : $this->secrets->read($secret->key);
    }

    private function ensureCredential(PublishedResourceRecord $resource): void
    {
        if ($resource->access === 'credential' && $this->credentialFor($resource) === null) {
            $this->createCredential($resource);
        }
    }
}
