<?php

namespace App\Integration\DTO;

use App\Integration\IsProtocolValue;
use Tempest\Mapper\Strict;

/**
 * Remember one directory and the next entry to read without listing all its filenames.
 */
#[Strict]
final class FilesystemFrame
{
    /**
     * Carry a relative directory path and a nonnegative entry position.
     *
     * The cursor decoder checks these values and path safety before they are used.
     */
    public function __construct(
        #[IsProtocolValue('string')]
        public string $path,
        #[IsProtocolValue('nonnegative-integer')]
        public int $position,
    ) {}
}
