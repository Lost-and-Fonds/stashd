<?php

declare(strict_types=1);

namespace App\Commands\Api;

use App\Commands\CommandRecord;
use App\Http\Api\ApiJson;

final readonly class CommandResource
{
    public function __construct(
        private CommandRecord $command,
    ) {}

    public static function fromRecord(CommandRecord $command): self
    {
        return new self($command);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ApiJson::encode([
            'id' => (string) $this->command->id,
            'type' => $this->command->type->value,
            'state' => $this->command->state->value,
            'targetType' => $this->command->targetType,
            'targetId' => $this->command->targetId,
            'options' => $this->encodeForApi($this->command->options),
            'result' => $this->encodeForApi($this->command->result),
            'createdByUserId' => $this->command->createdByUserId === null ? null : (string) $this->command->createdByUserId,
            'createdAt' => $this->command->createdAt,
            'updatedAt' => $this->command->updatedAt,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    private function encodeForApi(?array $data): ?array
    {
        return $data === null ? null : ApiJson::encode($data);
    }
}
