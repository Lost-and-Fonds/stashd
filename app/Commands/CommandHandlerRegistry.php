<?php

declare(strict_types=1);

namespace App\Commands;

final readonly class CommandHandlerRegistry
{
    /** @param list<CommandHandler> $handlers */
    public function __construct(
        private array $handlers,
    ) {}

    public function handlerFor(CommandType $type): CommandHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->type() === $type) {
                return $handler;
            }
        }

        throw new InvalidCommandPayload('Unsupported command type: ' . $type->value);
    }
}
