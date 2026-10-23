<?php

namespace App\Integration;

use App\Administration\VaultConfig;
use App\RecordsAndArchives\AssetArchive;
use Closure;
use DirectoryIterator;
use InvalidArgumentException;
use OutOfBoundsException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Enumerate regular files recursively through the shared acknowledged Input port.
 *
 * Directory-entry positions and a depth-first stack form an opaque restart token;
 * memory is bounded by one batch and traversal depth, not collection cardinality.
 * Positions are best-effort under directory mutation, not a snapshot or rename index.
 * A later full scan observes changes missed by a concurrently changing directory.
 *
 * @phpstan-import-type Request from InputDiscovery
 * @phpstan-type Frame array{path: string, position: int}
 * @phpstan-type Cursor array{version: int, frames: list<Frame>, skipped: int, first: ?string, retryable: bool}
 */
final readonly class FilesystemDiscovery implements InputDiscovery
{
    /**
     * Bind existing root/source checks and configuration inspection for use-time safety.
     */
    public function __construct(
        private FilesystemRoots $roots,
        private VaultConfig $vault,
        private IntegrationConfigurations $configurations,
    ) {}

    /**
     * Stream bounded observations, checkpointing skipped entries with the traversal.
     *
     * Unsafe or unreadable entries are omitted with terminal partial coverage, never
     * followed. Root/storage failures throw and preserve earlier acknowledgments.
     * No file contents are read or hashed and no canonical identity is minted.
     */
    public function discover(array $request, Closure $commit): void
    {
        $root = $this->root($request);
        $cursor = $this->cursor($request['continuation']);
        $iterators = [];
        $items = [];
        $visited = 0;
        $skip = static function (string $path, string $reason, bool $retryable) use (&$cursor): void {
            $cursor['skipped']++;
            $cursor['first'] ??= $reason . ': ' . json_encode($path, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
            $cursor['retryable'] = $cursor['retryable'] || $retryable;
        };
        while ($cursor['frames'] !== []) {
            $depth = count($cursor['frames']) - 1;
            $frame = $cursor['frames'][$depth];
            if (! isset($iterators[$depth])) {
                try {
                    $directory = $this->roots->canonical($root . ($frame['path'] === '' ? '' : '/' . $frame['path']));
                    // shortcut: resume replays directory entries to the saved position; use an index/snapshot for costly repeated restarts or lossless concurrent mutation.
                    $iterator = new DirectoryIterator($directory);
                    try {
                        $iterator->seek($frame['position']);
                    } catch (OutOfBoundsException) {
                        // A changed directory may now end before the acknowledged offset.
                        $skip($frame['path'], 'Directory shortened before resume', true);
                        array_pop($cursor['frames']);
                        continue;
                    }
                    $iterators[$depth] = $iterator;
                } catch (InvalidArgumentException|UnexpectedValueException $error) {
                    if ($depth === 0) {
                        throw $error;
                    }
                    $skip($frame['path'], 'Directory unavailable or unsafe', true);
                    array_pop($cursor['frames']);
                    continue;
                }
            }
            $iterator = $iterators[$depth];
            if (! $iterator->valid()) {
                unset($iterators[$depth]);
                array_pop($cursor['frames']);
                continue;
            }
            $name = $iterator->getFilename();
            $iterator->next();
            $cursor['frames'][$depth]['position']++;
            if ($name === '.' || $name === '..') {
                continue;
            }
            $visited++;
            $relative = ltrim($frame['path'] . '/' . $name, '/');
            $path = $root . '/' . $relative;
            if (str_contains($relative, '\\') || preg_match('/[\x00-\x1f\x7f]/', $relative) || preg_match('//u', $relative) !== 1) {
                $skip($relative, 'Unsupported relative filename', false);
            } else {
                try {
                    $this->roots->canonical(dirname($path));
                    clearstatcache(true, $path);
                    $stat = @lstat($path);
                    if ($stat === false) {
                        $skip($relative, 'Entry disappeared or could not be read', true);
                    } elseif (($stat['mode'] & 0o170_000) === 0o040_000) {
                        $cursor['frames'][] = ['path' => $relative, 'position' => 0];
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
                                    'input_id' => $request['input_id'],
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
            if ($visited >= $request['maximum_items_per_batch']) {
                $this->root($request);
                $commit(['items' => $items, 'progress' => ['more' => json_encode($cursor, JSON_THROW_ON_ERROR)]]);
                $items = [];
                $visited = 0;
            }
        }
        $this->root($request);
        $finish = $cursor['skipped'] === 0
            ? ['kind' => 'exhaustive', 'refresh_state' => null]
            : [
                'kind' => 'partial',
                'deficiencies' => [[
                    'disposition' => $cursor['retryable'] ? 'retryable' : 'terminal',
                    'diagnostic' => [
                        'message' => $cursor['skipped'] . ' entries excluded; first: ' . ($cursor['first'] ?? ''),
                        'evidence' => [['schema' => 'urn:stashd:filesystem:discovery:1', 'json' => json_encode(['skipped' => $cursor['skipped']], JSON_THROW_ON_ERROR)]],
                    ],
                ]],
            ];
        $commit(['items' => $items, 'progress' => ['finished' => $finish]]);
    }

    /**
     * Recheck live Input, Vault and destination roots before enumeration or acknowledgment.
     *
     * The component owns root semantics; the generic coordinator passes settings
     * unchanged. These checks assume operator-controlled directory entries.
     *
     * @param Request $request
     */
    private function root(array $request): string
    {
        $path = $request['options']['root'] ?? null;
        if (! is_string($path) || $this->vault->provider !== 'local') {
            throw new InvalidArgumentException('Filesystem discovery requires a configured local source and Vault.');
        }
        $root = $this->roots->canonical($path);
        $vault = $this->roots->canonical($this->vault->root, allowMissing: true);
        $this->roots->requireSeparate($root, $vault);
        foreach ($this->configurations->all() as $configuration) {
            if ($configuration['id'] === $request['input_id'] || $configuration['component'] !== 'filesystem') {
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
     * Decode only self-contained traversal state and reject unsafe frame paths.
     *
     * Counts and one diagnostic travel with the checkpoint, so process restart
     * cannot erase a known coverage gap. No collection-sized pending list is stored.
     *
     * @return Cursor
     */
    private function cursor(?string $value): array
    {
        if ($value === null) {
            return ['version' => 1, 'frames' => [['path' => '', 'position' => 0]], 'skipped' => 0, 'first' => null, 'retryable' => false];
        }
        $cursor = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        if (
            ! is_array($cursor)
            || ($cursor['version'] ?? null) !== 1
            || ! is_array($cursor['frames'] ?? null)
            || ! array_is_list($cursor['frames'])
            || ! is_int($cursor['skipped'] ?? null)
            || $cursor['skipped'] < 0
            || ! array_key_exists('first', $cursor)
            || $cursor['first'] !== null && ! is_string($cursor['first'])
            || $cursor['skipped'] > 0 && $cursor['first'] === null
            || ! is_bool($cursor['retryable'] ?? null)
        ) {
            throw new InvalidArgumentException('Invalid Filesystem discovery continuation.');
        }
        foreach ($cursor['frames'] as $frame) {
            if (
                ! is_array($frame)
                || ! is_string($frame['path'] ?? null)
                || ! is_int($frame['position'] ?? null)
                || $frame['position'] < 0
                || $frame['path'] !== ''
                && (
                    str_starts_with($frame['path'], '/')
                    || str_contains($frame['path'], '\\')
                    || preg_match('/[\x00-\x1f\x7f]/', $frame['path'])
                    || array_intersect(explode('/', $frame['path']), ['', '.', '..']) !== []
                )
            ) {
                throw new InvalidArgumentException('Invalid Filesystem discovery frame.');
            }
        }
        /** @var Cursor $cursor */
        return $cursor;
    }
}
