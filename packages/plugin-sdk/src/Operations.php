<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final readonly class Choice
{
    public function __construct(public string $value, public string $label)
    {
    }
}

final readonly class OperationRequest
{
    /**
     * @param list<Setting> $settings
     * @param list<Setting> $payload
     */
    public function __construct(public string $name, public array $settings = [], public array $payload = [])
    {
    }
}

final readonly class OperationResult
{
    /**
     * @param list<Choice> $choices
     * @param list<Setting> $values
     */
    public function __construct(public array $choices = [], public array $values = [])
    {
    }
}
