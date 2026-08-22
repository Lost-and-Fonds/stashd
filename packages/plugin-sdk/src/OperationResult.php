<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final readonly class OperationResult
{
    /**
     * @param  list<Choice>  $choices
     * @param  list<Setting>  $values
     */
    public function __construct(public array $choices = [], public array $values = []) {}
}
