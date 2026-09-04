<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Jobs;

use App\Jobs\JobDefinition;
use App\Jobs\JobDefinitionRegistry;
use App\Jobs\JobMessage;
use App\Jobs\JobType;
use InvalidArgumentException;

test('job types require a namespace', function (): void {
    expect((string) new JobType('youtube.media.download'))->toBe('youtube.media.download');

    expect(fn (): JobType => new JobType('download'))->toThrow(InvalidArgumentException::class);
});

test('job definition registration rejects duplicate types', function (): void {
    $registry = new JobDefinitionRegistry();
    $definition = new JobDefinition(new JobType('test.job'), JobMessage::class, JobMessage::class, 'background');

    $registry->register($definition);

    expect(fn () => $registry->register($definition))->toThrow(InvalidArgumentException::class);
});
