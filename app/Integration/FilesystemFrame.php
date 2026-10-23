<?php

namespace App\Integration;

use Tempest\Mapper\Strict;

/**
 * Checkpoint one active directory without retaining a collection-sized filename list.
 */
#[Strict]
final class FilesystemFrame
{
    /**
     * Bind one validated relative directory and its nonnegative entry position.
     */
    public function __construct(
        #[IsProtocolValue('string')]
        public string $path,
        #[IsProtocolValue('nonnegative-integer')]
        public int $position,
    ) {}
}
