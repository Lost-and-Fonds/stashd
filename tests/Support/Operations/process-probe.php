<?php

declare(strict_types=1);

// Process-supervision control only: no simulated Jobs, preservation or application receipts.
pcntl_async_signals(true);
pcntl_signal(SIGTERM, ($argv[1] ?? '') === 'ignore' ? SIG_IGN : static fn () => exit(0));
fwrite(STDOUT, json_encode(['event' => 'ready', 'pid' => getmypid()], JSON_THROW_ON_ERROR) . PHP_EOL);
while (true) {
    usleep(20_000);
}
