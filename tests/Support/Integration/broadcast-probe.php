<?php

declare(strict_types=1);

use App\Integration\BroadcastCoordinator;
use App\Integration\BroadcastInvocation;
use App\Integration\BroadcastPublisher;
use App\Integration\DTO\PublishedFile;
use Tempest\Core\FrameworkKernel;

use function Tempest\Container\get;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
FrameworkKernel::boot(root: dirname(__DIR__, 3));

try {
    $publisher = new class($argv[2] ?? 'success') implements BroadcastPublisher {
        public array $bytes = [];
        public ?BroadcastInvocation $retained = null;
        public ?\App\Integration\BroadcastStream $stream = null;
        public string $reference = '';

        /**
         * Choose a deterministic lifecycle scenario without replacing host services.
         */
        public function __construct(
            private string $mode,
        ) {}

        /**
         * Exercise real collection grants, streams and provisional reporting.
         */
        public function publish(BroadcastInvocation $invocation, array $settings): string
        {
            $this->retained = $invocation;
            expect_denied(static fn () => $invocation->openAsset('fabricated', PHP_INT_MAX));
            if ($this->mode === 'many-reports') {
                if ($invocation->next(1000) !== null || $invocation->next(1) !== null) {
                    throw new RuntimeException('Empty collection did not retain EOF.');
                }

                for ($position = 0; $position < 201; $position++) {
                    $invocation->reportFiles(new PublishedFile('file-' . $position));
                }

                return 'complete';
            }

            if ($this->mode === 'no-eof') {
                $invocation->reportFiles(new PublishedFile('provisional'));

                return 'complete';
            }

            while (($items = $invocation->next(1)) !== null) {
                foreach ($items as $item) {
                    foreach ($item['assets'] as $asset) {
                        $this->reference = $asset['reference'];
                        expect_denied(static fn () => $invocation->openAsset($asset['id']));
                        $stream = $invocation->openAsset($asset['reference']);
                        $this->stream = $stream;
                        $bytes = '';
                        while (($chunk = $stream->read(3)) !== null) {
                            $bytes .= $chunk;
                        }

                        if ($stream->read() !== null) {
                            throw new RuntimeException('EOF was not sticky.');
                        }

                        $range = $invocation->openAsset($asset['reference'], strlen($bytes), PHP_INT_MAX);
                        if ($range->read() !== null) {
                            throw new RuntimeException('EOF range was not empty.');
                        }

                        expect_denied(static fn () => $invocation->openAsset($asset['reference'], strlen($bytes) + 1));
                        $range = $invocation->openAsset($asset['reference'], 0, 2);
                        if ($range->read() !== (strlen($bytes) === 0 ? null : substr($bytes, 0, 2)) || $range->read() !== null) {
                            throw new RuntimeException('Range escaped its extent.');
                        }

                        $this->bytes[] = base64_encode($bytes);
                        $invocation->reportFiles(new PublishedFile('output-' . count($this->bytes), $item['id'], $asset['id']));
                        if ($this->mode === 'duplicate') {
                            try {
                                $invocation->reportFiles(new PublishedFile('atomic-should-not-stick'), new PublishedFile('output-' . count($this->bytes)));
                                throw new LogicException('Duplicate report accepted.');
                            } catch (\Tempest\Database\Exceptions\QueryWasInvalid) {
                            }
                        }
                    }
                }
            }

            $invocation->reportMetadata(['schema' => 'proof@1', 'json' => '{"receipt":true}']);
            if ($this->mode === 'failure') {
                throw new RuntimeException('Deliberate publisher failure.');
            }

            if ($this->mode === 'not-applicable') {
                return 'not-applicable';
            }

            return 'complete';
        }
    };
    $result = get(BroadcastCoordinator::class)->execute($argv[1], $publisher);
    if ($publisher->retained !== null) {
        expect_denied(fn () => $publisher->retained->next(1));
        expect_denied(fn () => $publisher->retained->openAsset($publisher->reference));
        if ($publisher->stream !== null) {
            expect_denied(fn () => $publisher->stream->read());
        }
    }

    echo json_encode(['run' => $result, 'bytes' => $publisher->bytes], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage());
    exit(1);
}

/**
 * Require refusal while allowing any host denial exception to remain implementation-specific.
 */
function expect_denied(Closure $operation): void
{
    try {
        $operation();
    } catch (RuntimeException) {
        return;
    }

    throw new LogicException('Unauthorized operation succeeded.');
}
