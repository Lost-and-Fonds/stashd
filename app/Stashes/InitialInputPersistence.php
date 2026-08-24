<?php

declare(strict_types=1);

namespace App\Stashes;

interface InitialInputPersistence
{
    /** @param array<string, mixed> $options */
    public function persistDiscoveredInput(StashRecord $stash, PreflightExecutionResult $discovered, array $options = []): StashInputCommitResult;

    public function dispatchFollowups(StashRecord $stash, StashInputCommitResult $result): void;
}
