<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Jobs\JobIntent;
use App\Jobs\JobHandlerContext;
use App\Jobs\JobProgressUpdate;
use App\Jobs\JobRecord;
use App\Plugins\ExternalInputPluginRegistry;
use App\System\Activity\ActivityEventService;
use RuntimeException;
use Tempest\Database\Database;

/** Creates a stash and its first resolved Input as one database change. */
final readonly class CreateStashWithInitialInput
{
    public function __construct(
        private ExternalInputPluginRegistry $plugins,
        private DiscoverStashInput $discovery,
        private InitialInputPersistence $inputs,
        private StashRepository $stashes,
        private ActivityEventService $activity,
        private Database $database,
    ) {}

    /** @param array<string, mixed> $source
     *  @param array<string, mixed> $options
     */
    public function execute(string $name, SyncMode $syncMode, DownloadPolicy $downloadPolicy, OrganizationMode $organizationMode, ?string $description, string $pluginId, array $source, array $options): StashRecord
    {
        $discovered = $this->discover($pluginId, $source, $options);

        $stash = null;
        $input = null;
        $committed = $this->database->withinTransaction(function () use ($name, $syncMode, $downloadPolicy, $organizationMode, $description, $discovered, $options, &$stash, &$input): void {
            $stash = $this->stashes->create($name, $syncMode, $downloadPolicy, $organizationMode, $description);
            $input = $this->inputs->persistDiscoveredInput($stash, $discovered, $options);
        });

        if (! $committed || ! $stash instanceof StashRecord || ! $input instanceof StashInputCommitResult) {
            throw new RuntimeException('Failed to create stash and initial input.');
        }

        $this->activity->stashCreated($stash);
        $this->inputs->dispatchFollowups($stash, $input);

        return $stash;
    }

    /** @param array<string, mixed> $source
     *  @param array<string, mixed> $options
     */
    public function addToExisting(StashRecord $stash, string $pluginId, array $source, array $options, ?JobHandlerContext $context = null, ?JobRecord $job = null): StashInputCommitResult
    {
        $discovered = $this->discover($pluginId, $source, $options, $context, $job);
        $result = null;
        $committed = $this->database->withinTransaction(function () use ($stash, $discovered, $options, &$result): void {
            $result = $this->inputs->persistDiscoveredInput($stash, $discovered, $options);
        });

        if (! $committed || ! $result instanceof StashInputCommitResult) {
            throw new RuntimeException('Failed to create Input.');
        }

        $this->inputs->dispatchFollowups($stash, $result);

        return $result;
    }

    /** @param array<string, mixed> $source
     *  @param array<string, mixed> $options
     */
    private function discover(string $pluginId, array $source, array $options, ?JobHandlerContext $context = null, ?JobRecord $job = null): PreflightExecutionResult
    {
        $plugin = $this->plugins->definition($pluginId)
            ?? throw new \InvalidArgumentException('Input plugin not found.');
        $resolved = $this->plugins->resolveSource($pluginId, $plugin->normalizeSource($source));

        return $this->discovery->executeResolved(
            $resolved,
            $resolved->sourceUri->toString(),
            null,
            PreflightOrigin::Api,
            $options['provider'] ?? [],
            JobIntent::InitialBackfill,
            $context === null || $job === null ? null : static function (string $stage, ?float $fraction) use ($context, $job): void {
                $context->progress($job, $fraction === null ? JobProgressUpdate::indeterminate($stage) : JobProgressUpdate::ofPercent($fraction * 100, $stage));
            },
        );
    }
}
