<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Broadcasts\BroadcastRepository;
use App\Commands\CommandDispatchService;
use App\Commands\CommandId;
use App\Commands\CommandRecord;
use App\Commands\CommandRepository;
use App\Commands\CommandState;
use App\Commands\CommandType;
use App\Downloads\DownloadPolicyEvaluator;
use App\Jobs\JobIntent;
use InvalidArgumentException;
use RuntimeException;
use Tempest\Database\Database;

use function Tempest\Support\str;

/**
 * Commits a completed preflight into a stash input on an existing stash.
 *
 * Preflight only resolves identity and takes a cheap sample; this class re-runs
 * full discovery (the best available plugin capability) rather than replaying
 * preflight's frozen sample, then persists the
 * stash input, media items, sources, and stash items, deduplicating against
 * whatever the stash (and the wider Vault) already has.
 */
final readonly class CreateStashFromDiscovery
{
    public function __construct(
        private CommandRepository $commands,
        private StashRepository $stashes,
        private StashInputRepository $stashInputs,
        private DiscoveredItemCommitter $committer,
        private CommandDispatchService $commandDispatch,
        private DownloadPolicyEvaluator $downloadPolicy,
        private DiscoverStashInput $discovery,
        private BroadcastRepository $broadcasts,
        private Database $database,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function commitInput(StashRecord $stash, CommandRecord $preflightCommand, array $options = []): StashInputCommitResult
    {
        $preflight = $this->requireCompletedPreflight($preflightCommand);
        $preflightResult = $this->decodePreflightResult($preflight);

        $sourceUri = str((string) ($preflightResult['source_uri'] ?? ''))->trim()->toString();
        $sourceTitle = is_string($preflightResult['source_title'] ?? null) ? $preflightResult['source_title'] : null;
        $origin = is_string($preflightResult['origin'] ?? null) ? $preflightResult['origin'] : PreflightOrigin::Api->value;

        if ($sourceUri === '') {
            throw new InvalidArgumentException('Preflight result is missing its source_uri.');
        }

        $discovered = $this->discovery->execute([
            'source_uri' => $sourceUri,
            'source_title' => $sourceTitle,
            'origin' => $origin,
            'provider_options' => is_array($options['provider'] ?? null) ? $options['provider'] : [],
        ], JobIntent::InitialBackfill);

        return $this->commitDiscoveredInput($stash, $discovered, $options, (string) $preflight->id);
    }

    /**
     * Persists a resolved Input and its discovered items. The caller may wrap
     * this in a larger transaction when creating the Stash itself.
     *
     * @param array<string, mixed> $options
     */
    public function persistDiscoveredInput(StashRecord $stash, PreflightExecutionResult $discovered, array $options = []): StashInputCommitResult
    {

        $resolved = $discovered->resolvedInput;
        $discoveredItems = $discovered->discoveredItems;
        $declaredInputOptions = $discovered->inputOptions;
        $inputOptions = StashInputOptions::fromArray($options);

        $stashId = StashId::fromPrimaryKey($stash->id);
        $inputType = StashInputTypeMapper::fromProviderInputType($resolved->inputType);

        $syncMode = SyncMode::tryFrom((string) ($options['sync_mode'] ?? SyncMode::Automatic->value)) ?? SyncMode::Automatic;

        $stashInput = null;
        $counts = new DiscoveredItemCommitCounts();

        $isFirstInput = $this->stashInputs->listForStash($stashId) === [];
        $stashInput = $this->stashInputs->findByStashAndProviderInput(
            $stashId,
            $resolved->providerKey,
            $resolved->providerInputId,
        ) ?? $this->stashInputs->create(
            stashId: $stashId,
            providerKey: $resolved->providerKey,
            inputType: $inputType,
            sourceUri: $resolved->sourceUri->toString(),
            providerInputId: $resolved->providerInputId,
            title: $resolved->title,
            syncMode: $syncMode,
            options: $inputOptions,
        );

        if ($stash->iconUri === null && $resolved->sourceAvatarUri !== null) {
            $this->stashes->update($stash, iconUri: $resolved->sourceAvatarUri->toString());
        }

        if ($isFirstInput && $stash->name === 'New Stash' && $resolved->sourceTitle !== null) {
            $this->stashes->update($stash, name: $resolved->sourceTitle);
        }

        $counts = $this->committer->commit(
            stashId: $stashId,
            stashInputId: StashInputId::fromPrimaryKey($stashInput->id),
            resolved: $resolved,
            discoveredItems: $discoveredItems,
            inputOptions: $inputOptions,
            declaredInputOptions: $declaredInputOptions,
        );

        return new StashInputCommitResult(
            stashId: (string) $stash->id,
            stashInputId: (string) $stashInput->id,
            mediaItemsCreated: $counts->mediaItemsCreated,
            mediaItemsReused: $counts->mediaItemsReused,
            stashItemsCreated: $counts->stashItemsCreated,
            stashItemsReused: $counts->stashItemsReused,
            preflightCommandId: '',
            downloadableMediaItemIds: $counts->downloadableMediaItemIds,
        );
    }

    /** @param array<string, mixed> $options */
    public function commitDiscoveredInput(StashRecord $stash, PreflightExecutionResult $discovered, array $options = [], string $preflightCommandId = ''): StashInputCommitResult
    {
        $result = null;
        $committed = $this->database->withinTransaction(function () use ($stash, $discovered, $options, &$result): void {
            $result = $this->persistDiscoveredInput($stash, $discovered, $options);
        });

        if (! $committed || ! $result instanceof StashInputCommitResult) {
            throw new RuntimeException('Failed to commit stash input.');
        }

        $result = new StashInputCommitResult(
            stashId: $result->stashId,
            stashInputId: $result->stashInputId,
            mediaItemsCreated: $result->mediaItemsCreated,
            mediaItemsReused: $result->mediaItemsReused,
            stashItemsCreated: $result->stashItemsCreated,
            stashItemsReused: $result->stashItemsReused,
            preflightCommandId: $preflightCommandId,
            downloadableMediaItemIds: $result->downloadableMediaItemIds,
        );

        $this->dispatchFollowups($stash, $result);

        return $result;
    }

    public function dispatchFollowups(StashRecord $stash, StashInputCommitResult $result): void
    {
        $stashId = StashId::fromPrimaryKey($stash->id);

        if ($this->downloadPolicy->allowsAutomaticDownload($stash->downloadPolicy)) {
            foreach ($result->downloadableMediaItemIds as $mediaItemId) {
                $this->commandDispatch->dispatch(CommandType::ItemDownload, [
                    'mediaItemId' => $mediaItemId,
                    'stashId' => $stashId->toString(),
                ]);
            }
        }

        foreach ($this->broadcasts->listForStash($stashId) as $broadcast) {
            $this->commandDispatch->dispatch(CommandType::BroadcastRebuild, [
                'broadcast_id' => (string) $broadcast->id,
            ]);
        }
    }

    private function requireCompletedPreflight(CommandRecord $preflightCommand): CommandRecord
    {
        $command = $this->commands->find(CommandId::fromPrimaryKey($preflightCommand->id));

        if ($command === null || $command->type !== CommandType::StashPreflight) {
            throw new InvalidArgumentException('Preflight command not found.');
        }

        if ($command->state !== CommandState::Completed) {
            throw new InvalidArgumentException('Preflight command must be completed before adding an input.');
        }

        if ($command->result === null) {
            throw new InvalidArgumentException('Preflight command is missing stored results.');
        }

        return $command;
    }

    /** @return array<string, mixed> */
    private function decodePreflightResult(CommandRecord $command): array
    {
        return $command->result ?? throw new InvalidArgumentException('Preflight result is missing.');
    }
}
