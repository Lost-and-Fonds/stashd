<?php

namespace App\RecordsAndArchives;

use League\Uri\Urn;
use Ottosmops\Ocfl\OcflObject;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Tempest\Storage\Config\LocalStorageConfig;
use Tempest\Storage\GenericStorage;
use UnexpectedValueException;

/** Preserves complete logical Asset snapshots; no Item version pins are implied. */
final readonly class AssetArchive
{
    private const string RECORD = '.stashd/asset.json';
    private const string POLICY = 'exact-item-uri-key-v1';

    public function __construct(
        private Vault $vault,
        private ItemArchive $items,
    ) {}

    public function objectId(string $itemUri, string $assetKey): Urn
    {
        $item = $this->items->objectIdForUri($itemUri);
        self::validatePath($assetKey);

        return $this->vault->urnFromUuid(IdentifierType::Asset, Uuid::v5(
            Uuid::fromString(Uuid::NAMESPACE_URL),
            'stashd:asset:' . self::POLICY . ':' . json_encode([$item->toString(), $assetKey], JSON_THROW_ON_ERROR),
        ));
    }

    /** @param array<string, string> $files Complete version, logical path => absolute local source. */
    public function preserve(string $itemUri, string $assetKey, array $files): array
    {
        $id = $this->objectId($itemUri, $assetKey);
        if ($files === []) {
            throw new InvalidAsset('An Asset must contain at least one logical file.');
        }
        foreach ($files as $path => $source) {
            self::validatePath($path);
            if ($path === '.stashd' || str_starts_with($path, '.stashd/')) {
                throw new InvalidAsset('The .stashd namespace is reserved for archival metadata.');
            }
            foreach (array_keys($files) as $other) {
                if (str_starts_with($other, $path . '/')) {
                    throw new InvalidAsset('A logical file cannot also be a directory.');
                }
            }
            self::validateSource($source);
        }

        // Private acquisition snapshot: producers cannot mutate the paths OCFL hashes and copies.
        $directory = sys_get_temp_dir() . '/stashd-asset-' . bin2hex(random_bytes(16));
        if (! mkdir($directory, 0o700)) {
            throw new RuntimeException('Could not create private acquisition staging.');
        }
        $staging = new GenericStorage(new LocalStorageConfig(path: $directory));
        try {
            $accepted = [];
            $staged = [];
            foreach ($files as $path => $source) {
                $input = fopen($source, 'rb');
                if ($input === false) {
                    throw new InvalidAsset('Could not open acquisition source.');
                }
                try {
                    self::validateSource($source);
                    $before = fstat($input);
                    $current = stat($source);
                    if ($before === false || $current === false || $before['ino'] !== $current['ino'] || $before['dev'] !== $current['dev']) {
                        throw new InvalidAsset('Acquisition source changed while opening.');
                    }
                    $name = (string) count($accepted);
                    $staging->writeStream($name, $input);
                    $after = fstat($input);
                    if (
                        $after === false
                        || array_intersect_key($before, array_flip(['size', 'mtime', 'ctime'])) !== array_intersect_key($after, array_flip(['size', 'mtime', 'ctime']))
                    ) {
                        throw new InvalidAsset('Acquisition source changed while copying.');
                    }
                } finally {
                    fclose($input);
                }
                $stream = $staging->readStream($name);
                $accepted[$path] = self::evidence($stream);
                $staged[$path] = $directory . '/' . $name;
            }
            ksort($accepted);
            $record = [
                'format' => 'stashd-asset',
                'schema_version' => 1,
                'identity_policy' => self::POLICY,
                'object_id' => $id->toString(),
                'item_uri' => $itemUri,
                'item_object_id' => $this->items->objectIdForUri($itemUri)->toString(),
                'asset_key' => $assetKey,
                'acquisition' => 'local-copy',
                'files' => $accepted,
            ];
            $staged[self::RECORD] = ['contents' => json_encode($record, JSON_THROW_ON_ERROR)];
            $object = $this->vault->commitSnapshot($id, $staged, function (?OcflObject $existing) use ($id, $itemUri, $assetKey): void {
                if ($existing !== null && $existing->id() !== $id->toString()) {
                    throw new UnexpectedValueException('OCFL identity does not match the Asset.');
                }
                if ($existing !== null && $existing->head() !== '') {
                    $record = $this->inspect($id, $existing->head());
                    if ($record['item_uri'] !== $itemUri || $record['asset_key'] !== $assetKey) {
                        throw new UnexpectedValueException('Asset ownership does not match.');
                    }
                }
            });

            // No receipt until independent storage reads match accepted bytes AND OCFL state.
            $receipt = $this->inspect($id, $object->head());
            if ($receipt['files'] !== $accepted) {
                throw new UnexpectedValueException('Committed evidence differs from the accepted acquisition snapshot.');
            }

            return $receipt;
        } finally {
            $staging->deleteDirectory();
        }
    }

    /**
     * Read and verify an exact version, including its persisted archival identity.
     * @return array{files: array<string, array{size: int, algorithm: string, digest: string}>, ...}
     */
    public function inspect(Urn $id, string $version): array
    {
        $object = $this->vault->open()->getObject($id->toString());
        $record = json_decode($this->vault->readObjectContent($id, $version, self::RECORD), true, flags: JSON_THROW_ON_ERROR);
        if (
            ! is_array($record)
            || ($record['format'] ?? null) !== 'stashd-asset'
            || ($record['schema_version'] ?? null) !== 1
            || ($record['identity_policy'] ?? null) !== self::POLICY
            || ($record['object_id'] ?? null) !== $id->toString()
            || ! is_string($record['item_uri'] ?? null)
            || ! is_string($record['asset_key'] ?? null)
            || ! is_array($record['files'] ?? null)
            || $record['files'] === []
            || $this->objectId($record['item_uri'], $record['asset_key'])->toString() !== $id->toString()
            || ($record['item_object_id'] ?? null) !== $this->items->objectIdForUri($record['item_uri'])->toString()
        ) {
            throw new UnexpectedValueException('Unsupported or inconsistent archived Asset identity.');
        }
        $paths = [...array_keys($record['files']), self::RECORD];
        sort($paths);
        if ($paths !== $object->logicalPaths($version)) {
            throw new UnexpectedValueException('Asset file set differs from OCFL version.');
        }
        $verified = [];
        foreach ($paths as $path) {
            $stream = $this->vault->readObjectStream($id, $version, $path);
            $actual = self::evidence($stream);
            if ($path !== self::RECORD && $actual !== $record['files'][$path]) {
                throw new UnexpectedValueException('Archived bytes differ from accepted byte evidence.');
            }
            if ($path !== self::RECORD) {
                $verified[$path] = $actual;
            }
            $digest = $actual['digest'];
            if ($object->inventory->digestAlgorithm->value !== 'sha512' || ! in_array($path, $object->inventory->versions[$version]->state[$digest] ?? [], true)) {
                throw new UnexpectedValueException('Archived bytes differ from OCFL inventory.');
            }
        }

        return [...$record, 'files' => $verified, 'version' => $version];
    }

    /**
     * Caller owns the bounded-memory exact-version stream.
     * @return resource
     */
    public function readStream(Urn $id, string $version, string $logicalPath): mixed
    {
        self::validatePath($logicalPath);
        $record = $this->inspect($id, $version);
        if (! isset($record['files'][$logicalPath])) {
            throw new InvalidAsset('Logical file is absent from the requested Asset version.');
        }

        return $this->vault->readObjectStream($id, $version, $logicalPath);
    }

    private static function validatePath(mixed $path): void
    {
        if (
            ! is_string($path)
            || $path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || preg_match('/^[a-zA-Z]:|[\x00-\x1f\x7f]/', $path)
            || array_intersect(explode('/', $path), ['', '.', '..']) !== []
        ) {
            throw new InvalidAsset('Asset keys and logical paths must be safe relative paths.');
        }
    }

    private static function validateSource(mixed $source): void
    {
        if (! is_string($source) || ! str_starts_with($source, '/') || str_contains($source, "\0")) {
            throw new InvalidAsset('Acquisition source must be an absolute local file path.');
        }
        for ($cursor = $source; $cursor !== '/'; $cursor = dirname($cursor)) {
            if (is_link($cursor)) {
                throw new InvalidAsset('Acquisition sources may not traverse symbolic links.');
            }
        }
        if (! is_file($source) || ! is_readable($source)) {
            throw new InvalidAsset('Acquisition source must be a readable regular file.');
        }
    }

    /**
     * @param resource $stream
     * @return array{size: int, algorithm: string, digest: string}
     */
    private static function evidence(mixed $stream): array
    {
        if (! is_resource($stream)) {
            throw new RuntimeException('Could not open byte verification stream.');
        }
        try {
            $context = hash_init('sha512');
            $size = hash_update_stream($context, $stream);
            if (! feof($stream)) {
                throw new RuntimeException('Could not read complete verification stream.');
            }

            return ['size' => $size, 'algorithm' => 'sha512', 'digest' => hash_final($context)];
        } finally {
            fclose($stream);
        }
    }
}
