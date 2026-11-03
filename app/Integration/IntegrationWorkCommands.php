<?php

declare(strict_types=1);

namespace App\Integration;

use App\Operations\Worker;
use RuntimeException;
use Tempest\Console\Console;
use Tempest\Console\ConsoleCommand;

/**
 * Admit real Integration work; receipts are not claims of execution or preservation.
 */
final readonly class IntegrationWorkCommands
{
    /**
     * Resolve admission and saved run stores without reading sources.
     */
    public function __construct(
        private Worker $worker,
        private Console $console,
        private IntegrationConfigurations $configurations,
        private DiscoveryStore $discovery,
        private BroadcastStore $broadcast,
    ) {}

    /**
     * Save or resume the Input run before admitting discovery; failed admission leaves replayable work.
     */
    #[ConsoleCommand('input:discover')]
    public function discover(string $inputId): void
    {
        $configuration = $this->configurations->read('input', $inputId);
        $this->discovery->lock($inputId);
        try {
            $run = $this->discovery->start($configuration);
            // Save the replayable run before admission. A failed admission leaves it resumable.
            $assignment = $this->worker->do(new DiscoverInput($run['id']));
        } finally {
            $this->discovery->unlock($inputId);
        }

        $this->report(['run_id' => $run['id'], 'assignment_id' => (string) $assignment->id]);
    }

    /**
     * Admit the finished single-file run, coalescing repeated active requests.
     */
    #[ConsoleCommand('input:acquire')]
    public function acquire(string $runId): void
    {
        $run = $this->discovery->read($runId);
        if ($run['status'] !== 'finished' || $run['discovered_count'] !== 1) {
            throw new RuntimeException('Operations PR 1 requires a finished single-file discovery run.');
        }

        $assignment = $this->worker->do(new AcquireInput($runId));
        $this->report(['assignment_id' => (string) $assignment->id]);
    }

    /**
     * Admit publication for an existing frozen selection without resolving source paths.
     */
    #[ConsoleCommand('broadcast:build')]
    public function build(string $runId): void
    {
        $this->broadcast->read($runId);
        $assignment = $this->worker->do(new PublishBroadcast($runId));
        $this->report(['assignment_id' => (string) $assignment->id]);
    }

    /**
     * Print admission identities; execution outcomes must be inspected separately.
     */
    private function report(array $record): void
    {
        $this->console->writeRaw(json_encode($record, JSON_THROW_ON_ERROR) . PHP_EOL);
    }
}
