<?php

declare(strict_types=1);

namespace App\Persistence;

use Attribute;
use ReflectionClass;

/**
 * Opt concrete models into each supported mutation; omitted policies prohibit writes.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Persistence
{
    /**
     * Keep insertion, updates and deletion independent, with no implicit permission.
     */
    public function __construct(
        public Policy $insert = Policy::Forbidden,
        public Policy $update = Policy::Forbidden,
        public Policy $delete = Policy::Forbidden,
    ) {}

    /**
     * Cache only the concrete class's declaration; subclasses must opt in themselves.
     *
     * @param class-string<Model> $model
     */
    public static function forModel(string $model): self
    {
        static $policies = [];

        return $policies[$model] ??= (new ReflectionClass($model)->getAttributes(self::class)[0] ?? null)?->newInstance() ?? new self();
    }
}
