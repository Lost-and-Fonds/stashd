<?php

declare(strict_types=1);

namespace App\Downloads;

use App\Commands\CommandHandler;
use App\Commands\CommandId;
use App\Commands\CommandRecord;
use App\Commands\CommandRepository;
use App\Commands\CommandType;
use App\Commands\InvalidCommandPayload;
use App\Jobs\JobIntent;
use App\Jobs\JobRepository;
use App\Support\PrefixedUlid;
use App\Vault\MediaItemId;
use App\Vault\MediaItemRepository;

final readonly class AssetDownloadCaptionsCommandHandler implements CommandHandler
{
    public function __construct(private CommandRepository $commands, private JobRepository $jobs, private MediaItemRepository $mediaItems) {}

    public function type(): CommandType
    {
        return CommandType::AssetDownloadCaptions;
    }

    public function validate(array $options): void
    {
        $id = is_string($options['media_item_id'] ?? null) ? $options['media_item_id'] : '';

        if (! MediaItemId::isValid($id) || $this->mediaItems->find(MediaItemId::parse($id)) === null) {
            throw InvalidCommandPayload::withErrors(['Media item not found.']);
        }
    }

    public function createJobs(CommandRecord $command, array $options): array
    {
        $mediaItemId = is_string($options['media_item_id'] ?? null) ? $options['media_item_id'] : '';
        $languages = is_string($options['languages'] ?? null) ? $options['languages'] : 'en';
        $payload = ['media_item_id' => $mediaItemId, 'languages' => $languages, 'include_auto' => ($options['include_auto'] ?? false) === true];
        $command->options = $payload;
        $command->targetType = 'media_item';
        $command->targetId = $payload['media_item_id'];
        $this->commands->save($command);
        $entityId = PrefixedUlid::parse($payload['media_item_id']);

        if ($this->jobs->hasPendingOrProcessing(JobIntent::DownloadCaptions, $entityId)) {
            return [];
        }

        return [$this->jobs->create(intent: JobIntent::DownloadCaptions, commandId: CommandId::fromPrimaryKey($command->id), entityType: 'media_item', entityId: $entityId, priority: 40, payload: $payload)];
    }

    /** @param array<string, mixed> $options */
    public function extras(CommandRecord $command, array $options): array
    {
        return [];
    }
}
