<?php

declare(strict_types=1);

namespace App\Commands;

use App\Auth\UserId;
use App\Auth\UserRecord;
use App\System\Activity\ActivityEventRecord;
use App\System\Activity\ActivityEventService;
use App\System\Event\EventPublisher;
use RuntimeException;
use Tempest\Database\Database;

final readonly class CommandDispatchService
{
    public function __construct(
        private CommandRepository $commands,
        private CommandHandlerRegistry $handlers,
        private ActivityEventService $activity,
        private EventPublisher $publisher,
        private Database $database,
    ) {}

    /** @param array<string, mixed> $options */
    public function dispatch(CommandType $type, array $options, ?UserRecord $user = null): CommandDispatchResult
    {
        $handler = $this->handlers->handlerFor($type);
        $handler->validate($options);

        $command = null;
        $activity = null;
        $jobs = [];

        $committed = $this->database->withinTransaction(function () use ($handler, $options, $type, $user, &$command, &$activity, &$jobs): void {
            $command = $this->commands->create(
                type: $type,
                targetType: $handler->type()->value,
                options: $options,
                createdByUserId: $user === null ? null : UserId::fromPrimaryKey($user->id),
            );

            $activity = $this->activity->commandAccepted($command, publish: false);
            $jobs = $handler->createJobs($command, $options);
        });

        if (! $committed || ! $command instanceof CommandRecord || ! $activity instanceof ActivityEventRecord) {
            throw new RuntimeException('Command dispatch failed.');
        }

        $this->publisher->activityCreated($activity);

        foreach ($jobs as $job) {
            $this->publisher->jobCreated($job);
        }

        return new CommandDispatchResult(
            command: $command,
            jobs: $jobs,
            extras: $handler->extras($command, $options),
        );
    }
}
