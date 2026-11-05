<?php

declare(strict_types=1);

namespace App\Development;

use Laravel\Prompts\Support\Logger;
use RuntimeException;

/**
 * Use Laravel's logger protocol while turning a lost renderer into a clean run failure.
 * The stock logger ignores write failures and otherwise prints broken-pipe notices to the task.
 */
final class FilesystemStressTaskLogger extends Logger
{
    /**
     * Require the renderer to accept the entire message instead of emitting a terminal notice.
     * No publication or preservation progress is inferred from delivery of this UI message.
     */
    protected function write(string $message, ?string $type = null): void
    {
        $line = ($type === null ? $message : $this->prefix($type, $message)) . PHP_EOL;
        if (! is_resource($this->socket) || @fwrite($this->socket, $line) !== strlen($line)) {
            throw new RuntimeException('Prompts renderer became unavailable.');
        }
    }
}
