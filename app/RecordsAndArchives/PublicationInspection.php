<?php

namespace App\RecordsAndArchives;

use App\RecordsAndArchives\Storage\ArchiveStorage;
use App\RecordsAndArchives\Storage\OcflFilesystemAdapter;
use Ottosmops\Ocfl\DigestAlgorithm;
use Ottosmops\Ocfl\Inventory\InventoryReader;
use Ottosmops\Ocfl\Inventory\InventorySidecar;
use Ottosmops\Ocfl\Namaste;
use Ottosmops\Ocfl\NamasteType;
use Ottosmops\Ocfl\OcflObject;
use Throwable;
use UnexpectedValueException;

/**
 * Reconcile readable history against the atomically replaced root checksum.
 *
 * Only acknowledged inventories may establish history; complete-looking version
 * directories are not promoted. Inspection reports stranded material without
 * repairing it, and can expose older verified history despite root divergence.
 */
final class PublicationInspection
{
    public ?OcflObject $object = null;
    /** @var list<string> */
    public array $issues = [];

    /**
     * Inspect inventory candidates, historical prefixes and optional content fixity.
     *
     * Candidate validation errors are collected as issues rather than establishing
     * unverified history. Directory-listing failures before inspection propagate.
     * Content verification may be skipped for readers; no storage mutation occurs.
     */
    public function __construct(ArchiveStorage $storage, string $path, string $id, bool $verifyContent = true)
    {
        $fs = new OcflFilesystemAdapter($storage);
        $names = $fs->listDirectory($path);
        try {
            if (Namaste::find($fs, $path) !== NamasteType::ObjectRoot) {
                throw new UnexpectedValueException("Object declaration missing at {$path}.");
            }
        } catch (Throwable $exception) {
            $this->issues[] = $exception->getMessage();
            return;
        }
        $candidates = [$path . '/inventory.json'];
        foreach ($names as $name) {
            if (! preg_match('/^v[1-9][0-9]*$/D', $name)) {
                continue;
            }

            $candidates[] = $path . '/' . $name . '/inventory.json';
        }
        // Never promote the highest complete directory: only a root checksum can acknowledge it.
        foreach ([DigestAlgorithm::Sha512, DigestAlgorithm::Sha256] as $algorithm) {
            if (! $fs->fileExists($path . '/inventory.json.' . $algorithm->value)) {
                continue;
            }
            try {
                $acknowledged = InventorySidecar::readDigest($fs, $path, $algorithm);
                foreach ($candidates as $candidate) {
                    if (! $fs->fileExists($candidate)) {
                        continue;
                    }
                    $json = $fs->read($candidate);
                    if (! hash_equals($acknowledged, hash($algorithm->hashAlgorithm(), $json))) {
                        continue;
                    }
                    $inventory = InventoryReader::fromString($json);
                    if ($inventory->id !== $id || $inventory->digestAlgorithm !== $algorithm) {
                        throw new UnexpectedValueException('Acknowledged inventory identity or digest algorithm differs.');
                    }
                    // Validate the acknowledged history without claiming stranded material is valid OCFL.
                    $view = new /**
                     * Present a fixed acknowledged inventory to the OCFL validator.
                     *
                     * Only the inspection view substitutes root inventory bytes and filters stranded
                     * root entries. Content and historical inventories still come from real storage.
                     */ readonly class($storage, $path, $json, $inventory->versionSequence()) extends OcflFilesystemAdapter {
                        /**
                         * Capture the acknowledged root bytes and version names for this validation view.
                         *
                         * @param list<string> $versions
                         */
                        public function __construct(
                            ArchiveStorage $storage,
                            private string $root,
                            private string $json,
                            private array $versions,
                        ) {
                            parent::__construct($storage);
                        }

                        /**
                         * Return captured root inventory bytes while delegating other reads to storage.
                         */
                        public function read(string $path): string
                        {
                            return $path === $this->root . '/inventory.json' ? $this->json : parent::read($path);
                        }

                        /**
                         * Hash captured root inventory bytes so validation checks the same bytes it parses.
                         */
                        public function digestFile(string $path, DigestAlgorithm $algorithm): string
                        {
                            return $path === $this->root . '/inventory.json' ? hash($algorithm->hashAlgorithm(), $this->json) : parent::digestFile($path, $algorithm);
                        }

                        /**
                         * Expose the captured root inventory as present even when inspecting a historical candidate.
                         */
                        public function fileExists(string $path): bool
                        {
                            return $path === $this->root . '/inventory.json' || parent::fileExists($path);
                        }

                        /**
                         * Hide unacknowledged root entries from validation of the selected history.
                         *
                         * Other directories retain their actual listing; this view never deletes files.
                         */
                        public function listDirectory(string $path): array
                        {
                            return $path === $this->root
                                ? array_values(array_filter(parent::listDirectory($path), fn (string $name): bool => in_array(
                                    $name,
                                    [...$this->versions, 'inventory.json', 'inventory.json.sha512', 'inventory.json.sha256', '0=ocfl_object_1.1', 'extensions', 'logs'],
                                    true,
                                )))
                                : parent::listDirectory($path);
                        }
                    };
                    $object = new OcflObject($path, $inventory, $fs);
                    if ($verifyContent) {
                        $report = new OcflObject($path, $inventory, $view)->validate();
                        if (! $report->isValid()) {
                            throw new UnexpectedValueException(implode('; ', array_map(fn ($issue): string => $issue->message . ' [' . $issue->path . ']', $report->errors())));
                        }
                    }
                    // The library validator compares logical names, not the entire historical state.
                    /** @var array{versions: array<string, mixed>} $raw */
                    $raw = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                    if ($inventory->head === '' || array_key_last($raw['versions']) !== $inventory->head) {
                        throw new UnexpectedValueException('Acknowledged inventory has no consistent head.');
                    }
                    $prefix = [];
                    foreach ($inventory->versionSequence() as $index => $version) {
                        if ($version !== 'v' . ($index + 1)) {
                            throw new UnexpectedValueException('Acknowledged Stashd history has a non-contiguous version sequence.');
                        }
                        $prefix[$version] = $raw['versions'][$version];
                        $versionPath = $path . '/' . $version;
                        $historical = InventoryReader::fromFilesystem($fs, $versionPath . '/inventory.json');
                        /** @var array{versions: array<string, mixed>} $historicalRaw */
                        $historicalRaw = json_decode($fs->read($versionPath . '/inventory.json'), true, flags: JSON_THROW_ON_ERROR);
                        if (
                            ! InventorySidecar::verify($fs, $versionPath, $algorithm)
                            || $historical->head !== $version
                            || $historical->id !== $id
                            || $historical->digestAlgorithm !== $algorithm
                        ) {
                            throw new UnexpectedValueException("Invalid committed inventory at {$versionPath}.");
                        }
                        if ($historicalRaw['versions'] !== $prefix) {
                            throw new UnexpectedValueException("Historical inventory state differs at {$versionPath}.");
                        }
                    }
                    if ($fs->read($path . '/' . $inventory->head . '/inventory.json') !== $json) {
                        throw new UnexpectedValueException('Acknowledged inventory differs from its immutable head copy.');
                    }
                    $this->object = $object;
                    if ($candidate !== $path . '/inventory.json') {
                        $this->issues[] = "Root inventory differs from acknowledged checksum; verified history ends at {$inventory->head}; selected {$candidate}.";
                    }
                    break 2;
                }
            } catch (Throwable $exception) {
                $this->issues[] = $exception->getMessage();
            }
        }
        $allowed = ['0=ocfl_object_1.1', 'extensions', 'logs'];
        if ($this->object !== null) {
            $allowed = [...$allowed, 'inventory.json', 'inventory.json.' . $this->object->inventory->digestAlgorithm->value, ...$this->object->versionNames()];
        } elseif ($names !== [] && array_diff($names, ['0=ocfl_object_1.1', '.ocfl-pending-meta.json']) === []) {
            // A pristine uncommitted shell is safe to retry; it is never preserved history.
            $allowed[] = '.ocfl-pending-meta.json';
        }
        foreach (array_diff($names, $allowed) as $name) {
            $this->issues[] = "Unacknowledged or incomplete material: {$path}/{$name}";
        }
        if ($this->object === null && $this->issues !== []) {
            array_unshift($this->issues, "No verified committed history established for {$id} at {$path}.");
        }
    }

    /**
     * Return acknowledged head and versions alongside reconciliation issues.
     *
     * A null head means no committed Object was established, including a pristine
     * uncommitted shell. An empty issue list alone does not imply committed history.
     *
     * @return array{head: ?string, versions: list<string>, issues: list<string>}
     */
    public function result(): array
    {
        return ['head' => $this->object?->head(), 'versions' => $this->object?->versionNames() ?? [], 'issues' => $this->issues];
    }
}
