<?php

namespace App\RecordsAndArchives;

use Closure;
use JsonException;
use League\Uri\Urn;
use Ottosmops\Ocfl\Validation\OcflException;
use OutOfBoundsException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Seal local custody and recover one Item delta using verified OCFL publication.
 *
 * Evidence lives in the Vault independently of SQL. All publication is serialized
 * with ordinary local Vault writers. Damaged or ambiguous evidence is retained,
 * never repaired or promoted. This service guarantees process-crash recovery,
 * not device durability or distributed fencing.
 *
 * @phpstan-type Pin array{object_id: string, version: string}
 * @phpstan-type Step array{object_id: string, before: ?string, version: string}
 * @phpstan-type Change array{file: string, size: int, digest: string}
 * @phpstan-type Delta array{id: string, uri: string, changes: array<string, Change>, metadata: array<string, mixed>, preconditions: array{pins?: list<Pin>}}
 * @phpstan-type Candidate array{format: string, vault: string, base: ?string, delta: Delta}
 * @phpstan-type Plan array{candidate: string, assets: array<string, Step>, item: Step, metadata: array, pins: list<Pin>}
 */
final readonly class PreservationTransaction
{
    /**
     * Bind the existing archive services to the same local Vault instance.
     */
    public function __construct(
        private Vault $vault,
        private ItemArchive $items,
        private AssetArchive $assets,
    ) {}

    /**
     * Adopt verified private bytes and atomically seal an immutable explicit delta.
     *
     * Repeated IDs must have identical semantics and byte evidence. Producer
     * paths do not participate in identity. No archival Object is published here.
     * Unfinished preparation directories are retained but cannot be recovered.
     *
     * @param array<array-key, string> $changes Asset key to absolute source file.
     * @param array<array-key, mixed> $metadata Explicit field assignments; omitted fields survive.
     * @param array{pins?: list<array{object_id: string, version: string}>} $preconditions
     * @param null|Closure(string): void $boundary Completed application boundary observer.
     */
    public function prepare(string $id, string $uri, array $changes, array $metadata = [], array $preconditions = [], ?Closure $boundary = null): array
    {
        try {
            if ($id === '' || strlen($id) > 512 || preg_match('/[\x00-\x1f\x7f]/', $id)) {
                throw new InvalidItemRevision('A bounded nonempty transaction identity is required.');
            }
            $itemId = $this->items->objectIdForUri($uri);
            json_encode([$metadata, $preconditions], JSON_THROW_ON_ERROR);
            if (array_diff(array_keys($preconditions), ['pins']) !== []) {
                throw new InvalidItemRevision('Unsupported transaction precondition.');
            }
            $this->validatePreconditions($preconditions);
            $this->items->validateMetadata($metadata);
            foreach ($metadata as $key => $value) {
                if (! is_string($key)) {
                    throw new InvalidItemRevision('Metadata deltas require named fields.');
                }
            }
            foreach ($changes as $key => $source) {
                if (! is_string($key)) {
                    throw new InvalidAsset('Asset keys must be strings.');
                }
                $this->assets->objectId($uri, $key);
                AssetArchive::validateSource($source);
            }
        } catch (InvalidAsset|InvalidItemRevision|JsonException|UnexpectedValueException $error) {
            return ['status' => 'rejected', 'transaction_id' => $id, 'reason' => $error->getMessage()];
        }
        /** @var array<string, string> $changes */
        /** @var array<string, mixed> $metadata */
        return $this->vault->exclusivePublication(function () use ($id, $uri, $changes, $metadata, $preconditions, $itemId, $boundary): array {
            $directory = $this->directory($id);
            ksort($changes);
            $accepted = [];
            if (is_dir($directory)) {
                try {
                    $existing = $this->candidate($id);
                    foreach ($changes as $key => $source) {
                        $accepted[$key] = ['file' => 'custody-' . count($accepted), 'size' => filesize($source), 'digest' => hash_file('sha512', $source)];
                    }
                    $semantics = ['id' => $id, 'uri' => $uri, 'changes' => $accepted, 'metadata' => $metadata, 'preconditions' => $preconditions];
                    if ($existing['delta'] !== $semantics) {
                        return ['status' => 'conflict', 'transaction_id' => $id];
                    }
                    return $this->prepared($id, $existing);
                } catch (UnexpectedValueException|JsonException $error) {
                    return $this->quarantined($id, $error);
                }
            }
            $staging = $directory . '.preparing-' . bin2hex(random_bytes(12));
            if (! mkdir($staging, 0o700)) {
                throw new RuntimeException('Could not create transaction custody.');
            }
            foreach ($changes as $key => $source) {
                $name = 'custody-' . count($accepted);
                $accepted[$key] = ['file' => $name, ...$this->acquire($source, $staging . '/' . $name)];
            }
            $semantics = ['id' => $id, 'uri' => $uri, 'changes' => $accepted, 'metadata' => $metadata, 'preconditions' => $preconditions];
            $head = $this->head($itemId);
            $candidate = ['format' => 'stashd-transaction-1', 'vault' => $this->vaultId(), 'base' => $head, 'delta' => $semantics];
            $this->validatePreconditions($preconditions);
            $json = json_encode($candidate, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $this->write($staging . '/candidate.json', $json);
            $this->write($staging . '/candidate.sha512', hash('sha512', $json));
            $boundary?->__invoke('before-seal');
            if (! rename($staging, $directory)) {
                throw new RuntimeException('Could not seal transaction custody.');
            }
            $boundary?->__invoke('after-seal');
            return $this->prepared($id, $candidate);
        });
    }

    /**
     * Recover verified completed steps and publish only absent planned versions.
     *
     * An unknown ID cannot adopt unrelated Objects. Explicit stale deltas conflict
     * before publication. Replays verify authoritative history even after a saved
     * completion fact. Evidence failures return quarantine without deleting files;
     * unexpected I/O and programming failures propagate to the caller.
     *
     * @param null|Closure(string): void $boundary Completed application boundary observer.
     */
    public function commit(string $id, ?Closure $boundary = null): array
    {
        return $this->vault->exclusivePublication(
            function () use ($id, $boundary): array {
                $directory = $this->directory($id);
                if (! is_dir($directory)) {
                    return ['status' => 'absent', 'transaction_id' => $id];
                }
                try {
                    $candidate = $this->candidate($id);
                    $delta = $candidate['delta'];
                    $itemId = $this->items->objectIdForUri($delta['uri']);
                    if (is_file($directory . '/plan.json')) {
                        $plan = $this->readEvidence($directory . '/plan.json');
                        $this->require(($plan['candidate'] ?? null) === $this->digest($id), 'Plan does not bind the sealed candidate.');
                    } else {
                        $this->require(! file_exists($directory . '/complete.json'), 'Completion has no publication plan.');
                        if ($this->head($itemId) !== $candidate['base']) {
                            return ['status' => 'conflict', 'transaction_id' => $id];
                        }
                        $this->requireNoLostPlan($id, $delta);
                        $current = $candidate['base'] === null ? ['metadata' => [], 'pins' => []] : $this->items->read($itemId, $candidate['base']);
                        foreach ($delta['preconditions']['pins'] ?? [] as $pin) {
                            if (! in_array($pin, $current['pins'], true)) {
                                return ['status' => 'conflict', 'transaction_id' => $id];
                            }
                        }
                        $pins = array_column($current['pins'], null, 'object_id');
                        $steps = [];
                        foreach ($delta['changes'] as $key => $change) {
                            $assetId = $this->assets->objectId($delta['uri'], $key);
                            $before = $this->head($assetId);
                            $steps[$key] = ['object_id' => $assetId->toString(), 'before' => $before, 'version' => $this->next($before)];
                            $pins[$assetId->toString()] = ['object_id' => $assetId->toString(), 'version' => $steps[$key]['version']];
                        }
                        $plan = [
                            'candidate' => $this->digest($id),
                            'assets' => $steps,
                            'item' => ['object_id' => $itemId->toString(), 'before' => $candidate['base'], 'version' => $this->next($candidate['base'])],
                            'metadata' => array_replace($current['metadata'], $delta['metadata']),
                            'pins' => array_values($pins),
                        ];
                        // Verify retained relationships before persisting any publication intent.
                        foreach ($current['pins'] as $pin) {
                            $this->verifiedAsset($pin, $delta['uri']);
                        }
                        $this->saveEvidence($directory . '/plan.json', $plan);
                    }
                    $this->validatePlan($candidate, $plan);
                    $binding = ['id' => $id, 'candidate' => $this->digest($id), 'plan' => hash('sha512', json_encode($plan, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))];
                    $this->validatePlan($candidate, $plan);
                    // Inspect every planned Object before writing anything, including damaged later steps.
                    $published = [];
                    foreach ($plan['assets'] as $key => $step) {
                        $published[$key] = $this->published($step, '.stashd/asset.json', $binding);
                        if ($published[$key]) {
                            $this->verifyChangedAsset($step, $delta, $key);
                        }
                    }
                    $itemPublished = $this->published($plan['item'], 'item.json', $binding);
                    $this->require(! $itemPublished || ! in_array(false, $published, true), 'Item exists without every required Asset.');
                    $this->require(
                        ! file_exists($directory . '/complete.json') || $itemPublished && ! in_array(false, $published, true),
                        'Completed transaction lost published Objects.',
                    );
                    foreach ($plan['assets'] as $key => $step) {
                        if (! $published[$key]) {
                            $boundary?->__invoke('before-asset:' . $key);
                            $this->assets->preserve($delta['uri'], $key, ['data.bin' => $directory . '/' . $delta['changes'][$key]['file']], $binding);
                        }
                        $this->require($this->published($step, '.stashd/asset.json', $binding), 'Asset publication is absent.');
                        $this->verifyChangedAsset($step, $delta, $key);
                        $boundary?->__invoke('asset-verified:' . $key);
                    }
                    if (! $itemPublished) {
                        $boundary?->__invoke('before-item');
                        $this->items->commitRevision($delta['uri'], $plan['metadata'], $plan['pins'], $binding);
                        $boundary?->__invoke('item-published');
                    }
                    $this->require($this->published($plan['item'], 'item.json', $binding), 'Item publication is absent.');
                    $record = $this->items->read($itemId, $plan['item']['version']);
                    $this->require($record['metadata'] === $plan['metadata'] && $record['pins'] === $plan['pins'], 'Published Item differs from the plan.');
                    foreach ($record['pins'] as $pin) {
                        $this->verifiedAsset($pin, $delta['uri']);
                    }
                    $boundary?->__invoke('item-verified');
                    $result = ['status' => 'complete', 'transaction_id' => $id, 'item' => array_intersect_key($plan['item'], array_flip(['object_id', 'version']))];
                    if (is_file($directory . '/complete.json')) {
                        $this->require($this->readEvidence($directory . '/complete.json') === $result, 'Completion contradicts authoritative publication.');
                    } else {
                        $boundary?->__invoke('before-ack');
                        $this->saveEvidence($directory . '/complete.json', $result);
                        $boundary?->__invoke('after-ack');
                    }
                    return $result;
                } catch (UnexpectedValueException|JsonException|OcflException|OutOfBoundsException|InvalidAsset|InvalidItemRevision $error) {
                    return $this->quarantined($id, $error);
                }
            },
            $boundary,
        );
    }

    /**
     * Discover healthy sealed candidates while reporting damaged evidence locally.
     *
     * SQL is never consulted. One quarantined bucket cannot hide other recoverable
     * candidates. Preparing directories are retained and never promoted.
     *
     * @return array{ids: list<string>, quarantined: list<array{path: string, reason: string}>}
     */
    public function discover(): array
    {
        $ids = [];
        $quarantined = [];
        $directories = glob($this->vault->transactionDirectory() . '/*', GLOB_ONLYDIR);
        if ($directories === false) {
            throw new RuntimeException('Could not discover transaction evidence.');
        }
        foreach ($directories as $directory) {
            if (str_contains(basename($directory), '.preparing-')) {
                continue;
            }
            try {
                $data = json_decode($this->read($directory . '/candidate.json'), true, flags: JSON_THROW_ON_ERROR);
                $this->require(is_array($data) && is_array($data['delta'] ?? null), 'Candidate delta is missing.');
                $id = $data['delta']['id'] ?? null;
                $this->require(is_string($id) && $directory === $this->directory($id), 'Candidate directory identity differs.');
                $this->candidate($id);
                $ids[] = $id;
            } catch (UnexpectedValueException|JsonException|InvalidItemRevision|InvalidAsset $error) {
                $quarantined[] = ['path' => $directory, 'reason' => $error->getMessage()];
            }
        }
        return ['ids' => $ids, 'quarantined' => $quarantined];
    }

    /**
     * Validate the sealed delta, Vault binding and every retained custody digest.
     *
     * @return Candidate
     */
    private function candidate(string $id): array
    {
        $directory = $this->directory($id);
        $json = $this->read($directory . '/candidate.json');
        $this->require(hash_equals($this->read($directory . '/candidate.sha512'), hash('sha512', $json)), 'Candidate seal differs.');
        $candidate = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->require(
            is_array($candidate) && ($candidate['format'] ?? null) === 'stashd-transaction-1' && ($candidate['vault'] ?? null) === $this->vaultId(),
            'Candidate format or Vault differs.',
        );
        $this->require(
            array_key_exists('base', $candidate) && ($candidate['base'] === null || is_string($candidate['base']) && preg_match('/^v[1-9][0-9]*$/D', $candidate['base']) === 1),
            'Malformed candidate predecessor.',
        );
        $delta = $candidate['delta'] ?? [];
        $this->require(
            ($delta['id'] ?? null) === $id
            && is_string($delta['uri'] ?? null)
            && is_array($delta['changes'] ?? null)
            && is_array($delta['metadata'] ?? null)
            && is_array($delta['preconditions'] ?? null),
            'Malformed candidate delta.',
        );
        $this->items->objectIdForUri($delta['uri']);
        $this->validatePreconditions($delta['preconditions']);
        foreach ($delta['metadata'] as $key => $value) {
            $this->require(is_string($key), 'Metadata delta requires named fields.');
        }
        $this->items->validateMetadata($delta['metadata']);
        foreach ($delta['changes'] as $key => $change) {
            $this->require(is_string($key), 'Asset key must be a string.');
            $this->assets->objectId($delta['uri'], $key);
            $this->require(is_array($change) && is_string($change['file'] ?? null) && preg_match('/^custody-[0-9]+$/D', $change['file']) === 1, 'Unsafe custody identity.');
            $path = $directory . '/' . $change['file'];
            $this->require(is_file($path) && ! is_link($path), 'Custody is missing or linked.');
            $digest = hash_file('sha512', $path);
            $size = filesize($path);
            if ($digest === false || $size === false) {
                throw new RuntimeException('Could not verify custody bytes.');
            }
            $this->require($digest === ($change['digest'] ?? null) && $size === ($change['size'] ?? null), 'Custody bytes differ.');
        }
        /** @var Candidate $candidate */
        return $candidate;
    }

    /**
     * Refuse to reconstruct missing intent when any exact version already names this ID.
     *
     * Matching publication content never substitutes for the lost immutable plan.
     *
     * @param Delta $delta
     */
    private function requireNoLostPlan(string $id, array $delta): void
    {
        $objects = [[$this->items->objectIdForUri($delta['uri']), 'item.json']];
        foreach ($delta['changes'] as $key => $change) {
            $objects[] = [$this->assets->objectId($delta['uri'], $key), '.stashd/asset.json'];
        }
        foreach ($objects as [$objectId, $path]) {
            if ($this->head($objectId) === null) {
                continue;
            }
            foreach ($this->vault->getObject($objectId)->versionNames() as $version) {
                $record = json_decode($this->vault->readObjectContent($objectId, $version, $path), true, flags: JSON_THROW_ON_ERROR);
                $this->require(($record['transaction']['id'] ?? null) !== $id, 'Published transaction has lost its immutable plan.');
            }
        }
    }

    /**
     * Check plan identities and resulting relationships against the sealed delta.
     *
     * @param Candidate $candidate
     * @param array $plan
     * @phpstan-assert Plan $plan
     */
    private function validatePlan(array $candidate, array $plan): void
    {
        $delta = $candidate['delta'];
        $this->require(is_array($plan['assets'] ?? null) && array_keys($plan['assets']) === array_keys($delta['changes']), 'Plan Asset set differs.');
        $this->require(is_array($plan['item'] ?? null) && array_key_exists('before', $plan['item']), 'Missing Item publication step.');
        $this->require(
            ($plan['item']['object_id'] ?? null) === $this->items->objectIdForUri($delta['uri'])->toString()
            && ($plan['item']['before'] ?? null) === $candidate['base']
            && ($plan['item']['version'] ?? null) === $this->next($candidate['base']),
            'Plan Item identity differs.',
        );
        $base = $candidate['base'] === null ? ['metadata' => [], 'pins' => []] : $this->items->read(Urn::fromString($plan['item']['object_id']), $candidate['base']);
        $pins = array_column($base['pins'], null, 'object_id');
        foreach ($plan['assets'] as $key => $step) {
            $this->require(
                is_string($key) && is_array($step) && array_key_exists('before', $step) && ($step['before'] === null || is_string($step['before'])),
                'Malformed Asset publication step.',
            );
            $this->require(
                ($step['object_id'] ?? null) === $this->assets->objectId($delta['uri'], $key)->toString() && ($step['version'] ?? null) === $this->next($step['before'] ?? null),
                'Plan Asset identity differs.',
            );
            $pins[$step['object_id']] = ['object_id' => $step['object_id'], 'version' => $step['version']];
        }
        $this->require(
            ($plan['metadata'] ?? null) === array_replace($base['metadata'], $delta['metadata']) && ($plan['pins'] ?? null) === array_values($pins),
            'Plan is not the sealed delta applied to its predecessor.',
        );
    }

    /**
     * Verify that an exact planned version belongs to this transaction or is absent.
     *
     * Full publication inspection must succeed; intended numbers or matching bytes
     * alone cannot establish ownership. Unrelated advanced heads block retry.
     *
     * @param Step $step
     */
    private function published(array $step, string $path, array $binding): bool
    {
        $id = Urn::fromString($step['object_id']);
        $head = $this->head($id);
        if ($head === null || ! in_array($step['version'], $this->vault->getObject($id)->versionNames(), true)) {
            $this->require($head === $step['before'], 'Publication predecessor changed.');
            return false;
        }
        $record = json_decode($this->vault->readObjectContent($id, $step['version'], $path), true, flags: JSON_THROW_ON_ERROR);
        $this->require(($record['transaction'] ?? null) === $binding, 'Exact OCFL revision has another transaction binding.');
        return true;
    }

    /**
     * Verify exact accepted bytes for a changed Asset against its sealed custody.
     *
     * @param Step $step
     * @param Delta $delta
     */
    private function verifyChangedAsset(array $step, array $delta, string $key): void
    {
        $record = $this->verifiedAsset($step, $delta['uri']);
        $change = $delta['changes'][$key];
        $this->require(
            $record['asset_key'] === $key && $record['files'] === ['data.bin' => ['size' => $change['size'], 'algorithm' => 'sha512', 'digest' => $change['digest']]],
            'Asset bytes differ from sealed custody.',
        );
    }

    /**
     * Verify all publication evidence and exact payload ownership for a retained pin.
     *
     * @param Pin|Step $pin
     */
    private function verifiedAsset(array $pin, string $uri): array
    {
        $id = Urn::fromString($pin['object_id']);
        $this->head($id);
        $record = $this->assets->inspect($id, $pin['version']);
        $this->require($record['item_uri'] === $uri, 'Pinned Asset belongs to another Item.');
        return $record;
    }

    /**
     * Read a verified acknowledged head, rejecting all stranded or corrupt material.
     */
    private function head(Urn $id): ?string
    {
        if (! $this->vault->open()->hasObject($id->toString())) {
            return null;
        }
        $state = $this->vault->inspectObject($id);
        $this->require($state['issues'] === [], 'Object publication requires reconciliation: ' . implode('; ', $state['issues']));
        return $state['head'];
    }

    /**
     * Copy a stable opened local source into private custody and measure accepted bytes.
     *
     * Both streams are closed on failure. Observed inode or stat changes reject the
     * source; retained private copies supply later publication, never producer paths.
     *
     * @return array{size: int, digest: string}
     */
    private function acquire(string $source, string $destination): array
    {
        AssetArchive::validateSource($source);
        $input = fopen($source, 'rb');
        $output = fopen($destination, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('Could not open custody streams.');
        }
        try {
            AssetArchive::validateSource($source);
            $before = fstat($input);
            $current = stat($source);
            $this->require($before !== false && $current !== false && $before['ino'] === $current['ino'] && $before['dev'] === $current['dev'], 'Source changed while opening.');
            $size = stream_copy_to_stream($input, $output);
            $after = fstat($input);
            $this->require(
                $size === $before['size']
                && $after !== false
                && array_intersect_key($before, array_flip(['size', 'mtime', 'ctime'])) === array_intersect_key($after, array_flip(['size', 'mtime', 'ctime'])),
                'Source changed during custody transfer.',
            );
        } finally {
            fclose($input);
            fclose($output);
        }
        $digest = hash_file('sha512', $destination);
        if ($digest === false) {
            throw new RuntimeException('Could not hash accepted custody bytes.');
        }
        return ['size' => $size, 'digest' => $digest];
    }

    /**
     * Reject unsupported or malformed explicit exact-pin preconditions before sealing.
     *
     * @phpstan-assert array{pins?: list<Pin>} $preconditions
     */
    private function validatePreconditions(array $preconditions): void
    {
        $this->require(
            array_diff(array_keys($preconditions), ['pins']) === [],
            'Unsupported preconditions.',
        );
        $pins = $preconditions['pins'] ?? [];
        $this->require(is_array($pins) && array_is_list($pins), 'Precondition pins must be a list.');
        foreach ($pins as $pin) {
            $this->require(
                is_array($pin)
                && count($pin) === 2
                && is_string($pin['object_id'] ?? null)
                && preg_match('/^urn:stashd:asset:[a-f0-9-]{36}$/D', $pin['object_id']) === 1
                && is_string($pin['version'] ?? null)
                && preg_match('/^v[1-9][0-9]*$/D', $pin['version']) === 1,
                'Malformed exact-pin precondition.',
            );
        }
    }

    /**
     * Resolve an opaque ID into one traversal-free evidence bucket.
     */
    private function directory(string $id): string
    {
        return $this->vault->transactionDirectory() . '/' . hash('sha256', $id);
    }

    /**
     * Read the candidate's physical SHA-512 seal without trusting a caller receipt.
     */
    private function digest(string $id): string
    {
        return $this->read($this->directory($id) . '/candidate.sha512');
    }

    /**
     * Return the persisted Vault identity after opening its authoritative storage.
     */
    private function vaultId(): string
    {
        $this->vault->open();
        $identity = $this->vault->getIdentity();
        $this->require($identity !== null, 'Vault identity is absent.');
        return $identity->identifier->toString();
    }

    /**
     * Describe physical fixture handles for a sealed candidate without publishing Objects.
     *
     * @param Candidate $candidate
     */
    private function prepared(string $id, array $candidate): array
    {
        $directory = $this->directory($id);
        $first = reset($candidate['delta']['changes']);
        return [
            'status' => 'sealed',
            'transaction_id' => $id,
            'candidate_path' => $directory . '/candidate.json',
            'candidate_sha512' => $this->digest($id),
            'custody_path' => $directory . '/' . ($first === false ? 'candidate.json' : $first['file']),
        ];
    }

    /**
     * Advance a validated predecessor identity when recording intent, never as proof.
     */
    private function next(?string $head): string
    {
        $this->require($head === null || preg_match('/^v[1-9][0-9]*$/D', $head) === 1, 'Malformed predecessor version.');
        return 'v' . ($head === null ? 1 : (int) substr($head, 1) + 1);
    }

    /**
     * Read retained evidence without following a symbolic link or treating absence as success.
     */
    private function read(string $path): string
    {
        $this->require(is_file($path) && ! is_link($path) && ! is_link(dirname($path)), 'Missing or linked transaction evidence.');
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException('Could not read transaction evidence.');
        }
        return $bytes;
    }

    /**
     * Write complete private bytes, surfacing short writes as storage failures.
     */
    private function write(string $path, string $bytes): void
    {
        if (file_put_contents($path, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Could not persist complete transaction evidence.');
        }
    }

    /**
     * Publish one immutable checksummed evidence record by sibling rename.
     *
     * The caller holds publication authority. Existing records are never replaced.
     */
    private function saveEvidence(string $path, array $data): void
    {
        $this->require(! file_exists($path), 'Immutable transaction evidence already exists.');
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(12));
        $this->write($temporary, json_encode(['sha512' => hash('sha512', $json), 'json' => $json], JSON_THROW_ON_ERROR));
        if (! rename($temporary, $path)) {
            throw new RuntimeException('Could not publish transaction evidence.');
        }
    }

    /**
     * Validate a physical evidence envelope before interpreting its JSON payload.
     */
    private function readEvidence(string $path): array
    {
        $envelope = json_decode($this->read($path), true, flags: JSON_THROW_ON_ERROR);
        $this->require(
            is_array($envelope)
            && is_string($envelope['json'] ?? null)
            && is_string($envelope['sha512'] ?? null)
            && hash_equals($envelope['sha512'], hash('sha512', $envelope['json'])),
            'Transaction evidence checksum differs.',
        );
        $data = json_decode($envelope['json'], true, flags: JSON_THROW_ON_ERROR);
        $this->require(is_array($data), 'Transaction evidence must be a JSON object.');
        return $data;
    }

    /**
     * Stop interpretation of ambiguous evidence without mutating retained files.
     *
     * @phpstan-assert true $condition
     */
    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new UnexpectedValueException($message);
        }
    }

    /**
     * Report local containment to callers while retaining every diagnostic byte.
     */
    private function quarantined(string $id, \Throwable $error): array
    {
        return ['status' => 'quarantined', 'transaction_id' => $id, 'reason' => $error->getMessage()];
    }
}
