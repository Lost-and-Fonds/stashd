<?php

declare(strict_types=1);

namespace App\Identity;

use Attribute;
use ReflectionClass;

/**
 * Preserve an external identity namespace when it differs from the concrete class name.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Identity
{
    /**
     * Declare the canonical namespace to retain across PHP class renames.
     */
    public function __construct(
        public string $namespace,
    ) {}

    /**
     * Lowercase the concrete short name without singularising or removing word separators.
     * Overrides belong to the concrete class, rather than being inherited from its parent.
     *
     * @param class-string $model
     */
    public static function forModel(string $model): string
    {
        static $names = [];
        if (isset($names[$model])) {
            return $names[$model];
        }

        $class = new ReflectionClass($model);

        return $names[$model] = ($class->getAttributes(self::class)[0] ?? null)?->newInstance()->namespace ?? strtolower($class->getShortName());
    }
}
