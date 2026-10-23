<?php

namespace App\Integration;

use App\Administration\VaultConfig;
use App\Integration\DTO\DiscoveryRequest;
use App\Integration\DTO\FilesystemCursor;
use App\Integration\DTO\FilesystemFrame;
use App\RecordsAndArchives\AssetArchive;
use Closure;
use DirectoryIterator;
use InvalidArgumentException;
use OutOfBoundsException;
use RuntimeException;
use Tempest\Container\Autowire;
use Tempest\Validation\Validator;
use UnexpectedValueException;

use function Tempest\Mapper\map;

/**
 * Find regular files in a directory tree using Core's shared Input interface.
 *
 * Keep only one batch and the active directory positions in memory. Save those
 * positions to resume after a restart. Changes to a directory can reorder its
 * entries, so this is not a snapshot: run a new full scan after changing the tree.
 */
#[Autowire]
final readonly class FilesystemDiscovery implements InputDiscovery
{
    /**
     * Use shared path checks and saved settings to recheck directories while scanning.
     */
    public function __construct(
        private FilesystemRoots $roots,
        private VaultConfig $vault,
        private IntegrationConfigurations $configurations,
        private Validator $validator,
    ) {}

    /**
     * Send found files in small batches, saving skipped-entry counts with the scan position.
     *
     * Skip unsafe or unreadable entries and report the scan as partial. If the root
     * becomes unsafe or storage fails, throw and leave earlier batches available for
     * retry. Read no file contents, compute no content hashes, and create no Item URIs.
     */
    public function discover(DiscoveryRequest $request, Closure $commit): void
    {
        $root = $this->root($request);
        $cursor = $this->cursor($request->continuation);
        $iterators = [];
        $items = [];
        $visited = 0;
        $skip = static function (string $path, string $reason, bool $retryable) use (&$cursor): void {
            $cursor->skipped++;
            $cursor->first ??= $reason . ': ' . json_encode($path, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
            $cursor->retryable = $cursor->retryable || $retryable;
        };
        while ($cursor->frames !== []) {
            $depth = count($cursor->frames) - 1;
            $frame = $cursor->frames[$depth];
            if (! isset($iterators[$depth])) {
                try {
                    $directory = $this->roots->canonical($root . ($frame->path === '' ? '' : '/' . $frame->path));
                    // shortcut: resuming re-reads directory entries up to the saved position; use an index or snapshot if restarts become costly or scans must handle concurrent changes without omissions.
                    $iterator = new DirectoryIterator($directory);
                    try {
                        $iterator->seek($frame->position);
                    } catch (OutOfBoundsException) {
                        // The directory may have fewer entries than when we saved this position.
                        $skip($frame->path, 'Directory shortened before resume', true);
                        array_pop($cursor->frames);
                        continue;
                    }

                    $iterators[$depth] = $iterator;
                } catch (InvalidArgumentException|UnexpectedValueException $error) {
                    if ($depth === 0) {
                        throw $error;
                    }

                    $skip($frame->path, 'Directory unavailable or unsafe', true);
                    array_pop($cursor->frames);
                    continue;
                }
            }

            $iterator = $iterators[$depth];
            if (! $iterator->valid()) {
                unset($iterators[$depth]);
                array_pop($cursor->frames);
                continue;
            }

            $name = $iterator->getFilename();
            $iterator->next();
            $cursor->frames[$depth]->position++;
            if ($name === '.' || $name === '..') {
                continue;
            }

            $visited++;
            $relative = ltrim($frame->path . '/' . $name, '/');
            $path = $root . '/' . $relative;
            try {
                $this->roots->relative($relative);
                $supported = true;
            } catch (InvalidArgumentException) {
                $supported = false;
            }

            if (! $supported) {
                $skip($relative, 'Unsupported relative filename', false);
            } else {
                try {
                    $this->roots->canonical(dirname($path));
                    clearstatcache(true, $path);
                    $stat = @lstat($path);
                    if ($stat === false) {
                        $skip($relative, 'Entry disappeared or could not be read', true);
                    } elseif (($stat['mode'] & 0o170_000) === 0o040_000) {
                        $cursor->frames[] = new FilesystemFrame($relative, 0);
                    } elseif (($stat['mode'] & 0o170_000) !== 0o100_000) {
                        $skip($relative, 'Symbolic links and special files are excluded', false);
                    } else {
                        AssetArchive::validateSource($path);
                        $items[] = [
                            'id' => hash('sha256', $relative),
                            'reference' => json_encode(['version' => 1, 'root' => $root, 'path' => $relative], JSON_THROW_ON_ERROR),
                            'source' => null,
                            'size_bytes' => $stat['size'],
                            'size_estimated' => false,
                            'metadata' => [[
                                'schema' => 'urn:stashd:filesystem:source:1',
                                'json' => json_encode([
                                    'input_id' => $request->input_id,
                                    'relative_path' => $relative,
                                    'root' => $root,
                                    'mtime' => $stat['mtime'],
                                ], JSON_THROW_ON_ERROR),
                            ]],
                        ];
                    }
                } catch (InvalidArgumentException $error) {
                    $skip($relative, 'Entry unavailable or unsafe', true);
                }
            }

            if ($visited >= $request->maximum_items_per_batch) {
                $this->root($request);
                $commit(['items' => $items, 'progress' => ['more' => map($cursor)->toJson()]]);
                $items = [];
                $visited = 0;
            }
        }

        $this->root($request);
        $finish = $cursor->skipped === 0
            ? ['kind' => 'exhaustive', 'refresh_state' => null]
            : [
                'kind' => 'partial',
                'deficiencies' => [[
                    'disposition' => $cursor->retryable ? 'retryable' : 'terminal',
                    'diagnostic' => [
                        'message' => $cursor->skipped . ' entries excluded; first: ' . ($cursor->first ?? ''),
                        'evidence' => [['schema' => 'urn:stashd:filesystem:discovery:1', 'json' => json_encode(['skipped' => $cursor->skipped], JSON_THROW_ON_ERROR)]],
                    ],
                ]],
            ];
        $commit(['items' => $items, 'progress' => ['finished' => $finish]]);
    }

    /**
     * Check that the source, Vault and configured destinations are still safe and separate.
     *
     * Check before scanning and before saving each batch. The Filesystem adapter
     * interprets these paths; Core passes settings unchanged. The operator must
     * control the directories: these checks cannot prevent concurrent path swaps.
     */
    private function root(DiscoveryRequest $request): string
    {
        $path = $request->options['root'] ?? null;
        if (! is_string($path) || $this->vault->provider !== 'local') {
            throw new InvalidArgumentException('Filesystem discovery requires a configured local source and Vault.');
        }

        $root = $this->roots->canonical($path);
        $vault = $this->roots->canonical($this->vault->root, allowMissing: true);
        $this->roots->requireSeparate($root, $vault);
        foreach ($this->configurations->all() as $configuration) {
            if ($configuration['id'] === $request->input_id || $configuration['component'] !== 'filesystem') {
                continue;
            }

            $other = $configuration['settings']['root'] ?? null;
            if (! is_string($other)) {
                throw new RuntimeException('Stored Filesystem configuration has no root.');
            }

            $other = $this->roots->canonical($other, allowMissing: true);
            $this->roots->requireSeparate($root, $other);
            $this->roots->requireSeparate($vault, $other);
        }

        return $root;
    }

    /**
     * Restore a checked scan position and skipped-entry summary from the saved JSON.
     */
    private function cursor(?string $value): FilesystemCursor
    {
        return FilesystemCursor::decode($value, $this->validator, $this->roots);
    }
}
