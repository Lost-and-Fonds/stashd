<?php

declare(strict_types=1);

use App\Jobs\Api\JobRealtimeResource;
use App\Jobs\Api\JobResource;
use App\Jobs\JobRecord;
use App\Jobs\JobState;
use Tempest\Database\PrimaryKey;

test('job API resources expose transient expected-size progress', function (): void {
    $job = new JobRecord('core.download', 'item', 'item_123', JobState::Processing, progressSizeBytes: 98765, progressSizeEstimated: true);
    $job->id = new PrimaryKey('job_123');

    expect(JobResource::fromRecord($job)->toArray())
        ->toHaveKey('progress_size_bytes', 98765)
        ->toHaveKey('progress_size_estimated', true)
        ->and(JobRealtimeResource::fromRecord($job)->toArray())
        ->toHaveKey('progress_size_bytes', 98765)
        ->toHaveKey('progress_size_estimated', true);
});
