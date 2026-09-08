<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Broadcasts\BroadcastItemRepository;
use App\Downloads\AcquireItemAssets;
use App\Http\Api\ApiJson;
use App\Jobs\JobHandler;
use App\Jobs\JobProgressReporter;
use App\Jobs\JobProgressUpdate;
use App\Jobs\JobRecord;
use App\Jobs\JobRepository;
use App\Jobs\JobDispatcher;
use App\Support\PrefixedUlid;
use App\Vault\ItemId;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final readonly class AcquireItemAssetsJobHandler implements JobHandler
{
    public function __construct(private AcquireItemAssets $acquisition, private JobRepository $jobs, private BroadcastItemRepository $broadcastItems, private JobDispatcher $dispatch) {}

    public function handle(JobRecord $job, JobProgressReporter $context): void
    {
        $payload = $job->payload ?? [];
        $itemId = ItemId::parse(ApiJson::string($payload['item_id'] ?? null));
        $roles = is_array($payload['roles'] ?? null) ? array_values(array_filter($payload['roles'], 'is_string')) : null;
        $options = is_array($payload['provider_options'] ?? null) ? array_filter($payload['provider_options'], static fn(mixed $value): bool => is_bool($value) || is_string($value)) : [];
        $context->progress($job, JobProgressUpdate::indeterminate('Acquiring available assets'));

        $result = $this->acquisition->execute($itemId, PrefixedUlid::parse((string) $job->id), $roles, $options);
        $job->progressPercent = 100.0;
        $job->progressLabel = 'Asset acquisition complete';
        $job->finishedAt = DateTime::now(Timezone::UTC);
        $this->jobs->save($job);
        $context->progress($job, JobProgressUpdate::ofPercent(100.0, $job->progressLabel));

        if ($result->files !== []) {
            foreach ($this->broadcastItems->listForItem($itemId) as $item) {
                $this->dispatch->dispatch(
                    'core.broadcast',
                    entityType: 'broadcast',
                    entityId: (string) $item->broadcast->id,
                    payload: ['broadcast_id' => (string) $item->broadcast->id, 'action' => 'rebuild'],
                    workload: 'background',
                );
            }
        }
    }
}
