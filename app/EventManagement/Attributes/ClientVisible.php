<?php

declare(strict_types=1);

namespace App\EventManagement\Attributes;

use Attribute;

/**
 * Allows a future client projection to expose only named event properties.
 * This designation does not permit general event serialization.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ClientVisible
{
    /**
     * Declares a stable topic and an explicit property allowlist.
     * Every listed value must be scalar or a backed enum at projection time.
     *
     * @param list<string> $fields Public event property names allowed in the projection.
     */
    public function __construct(
        public string $topic,
        public array $fields,
    ) {}
}
