<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\Commands\CommandHandler;
use App\Commands\CommandId;
use App\Commands\CommandRecord;
use App\Commands\CommandRepository;
use App\Commands\CommandType;
use App\Commands\InvalidCommandPayload;
use App\Http\Api\ApiJson;
use App\Jobs\JobIntent;
use App\Jobs\JobRepository;
use App\Support\PrefixedUlid;

final readonly class BroadcastCommandHandler implements CommandHandler
{
    public function __construct(
        private CommandRepository $commands,
        private JobRepository $jobs,
        private BroadcastRepository $broadcasts,
        private BroadcastItemRepository $broadcastItems,
        private CommandType $commandType,
    ) {}

    public function type(): CommandType
    {
        return $this->commandType;
    }

    /** @param array<string, mixed> $options */
    public function validate(array $options): void
    {
        if ($this->commandType === CommandType::BroadcastRebuildItem) {
            $item = $this->broadcastItemFromOptions($options);

            if ($item === null) {
                throw InvalidCommandPayload::withErrors(['Broadcast item not found.']);
            }

            return;
        }

        $broadcastId = $this->broadcastIdFromOptions($options);
        $broadcast = BroadcastId::isValid($broadcastId) ? $this->broadcasts->find(BroadcastId::parse($broadcastId)) : null;

        if ($broadcast === null) {
            throw InvalidCommandPayload::withErrors(['Broadcast not found.']);
        }

        if ($this->commandType === CommandType::BroadcastRotateToken) {
            $plugin = BroadcastPluginRegistry::findByKey($broadcast->type)?->plugin;

            if (! $plugin instanceof BroadcastPluginActions) {
                throw InvalidCommandPayload::withErrors(['This broadcast does not provide the requested plugin action.']);
            }
        }
    }

    /** @param array<string, mixed> $options */
    public function createJobs(CommandRecord $command, array $options): array
    {
        $commandId = CommandId::fromPrimaryKey($command->id);
        $payload = $this->normalizedPayload($options);
        $broadcast = $this->broadcasts->find(BroadcastId::parse(ApiJson::string($payload['broadcast_id'] ?? null)))
            ?? throw InvalidCommandPayload::withErrors(['Broadcast not found.']);
        $payload['stash_id'] = $broadcast->stashId;
        $targetId = $payload['broadcast_item_id'] ?? $payload['broadcast_id'];

        if (! is_string($targetId)) {
            throw InvalidCommandPayload::withErrors(['Broadcast target is required.']);
        }

        $command->options = $payload;
        $command->targetType = $this->commandType === CommandType::BroadcastRebuildItem ? 'broadcast_item' : 'broadcast';
        $command->targetId = $targetId;
        $this->commands->save($command);

        return [
            $this->jobs->create(
                intent: JobIntent::Broadcast,
                commandId: $commandId,
                entityType: $command->targetType,
                entityId: PrefixedUlid::parse($targetId),
                priority: 60,
                payload: $payload,
            ),
        ];
    }

    /** @param array<string, mixed> $options */
    public function extras(CommandRecord $command, array $options): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function normalizedPayload(array $options): array
    {
        if ($this->commandType === CommandType::BroadcastRebuildItem) {
            $item = $this->broadcastItemFromOptions($options)
                ?? throw InvalidCommandPayload::withErrors(['Broadcast item not found.']);

            return [
                'broadcast_id' => (string) $item->broadcastId,
                'broadcast_item_id' => (string) $item->id,
                'action' => 'rebuild_item',
            ];
        }

        return [
            'broadcast_id' => $this->broadcastIdFromOptions($options),
            'action' => $this->actionForType($this->commandType),
        ];
    }

    /** @param array<string, mixed> $options */
    private function broadcastIdFromOptions(array $options): string
    {
        $broadcastId = $this->stringOption($options, 'broadcastId', 'broadcast_id');

        if ($broadcastId === '') {
            throw InvalidCommandPayload::withErrors(['broadcast_id is required.']);
        }

        return $broadcastId;
    }

    private function actionForType(CommandType $type): string
    {
        return match ($type) {
            CommandType::BroadcastPlan => 'plan',
            CommandType::BroadcastRebuild => 'rebuild',
            CommandType::BroadcastRebuildItem => 'rebuild_item',
            CommandType::BroadcastVerify => 'verify',
            CommandType::BroadcastPrune => 'prune',
            CommandType::BroadcastDelete => 'delete',
            CommandType::BroadcastTrigger => 'trigger',
            CommandType::BroadcastRotateToken => 'rotate_token',
            default => throw InvalidCommandPayload::withErrors(['Unsupported broadcast command type.']),
        };
    }

    /** @param array<string, mixed> $options */
    private function broadcastItemFromOptions(array $options): ?BroadcastItemRecord
    {
        $id = $this->stringOption($options, 'broadcastItemId', 'broadcast_item_id');

        return BroadcastItemId::isValid($id) ? $this->broadcastItems->find(BroadcastItemId::parse($id)) : null;
    }

    /** @param array<string, mixed> $options */
    private function stringOption(array $options, string ...$keys): string
    {
        foreach ($keys as $key) {
            if (is_string($options[$key] ?? null)) {
                return trim($options[$key]);
            }
        }

        return '';
    }
}
