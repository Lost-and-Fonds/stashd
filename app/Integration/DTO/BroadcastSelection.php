<?php

declare(strict_types=1);

namespace App\Integration\DTO;

use InvalidArgumentException;
use League\Uri\Urn;

/**
 * Select one explicit Item revision rather than a moving archival head.
 */
final readonly class BroadcastSelection
{
    /**
     * Reject non-Item identities and implicit or malformed OCFL version names.
     */
    public function __construct(
        public string $itemId,
        public string $version,
    ) {
        Urn::fromString($itemId);
        if (! str_starts_with($itemId, 'urn:stashd:item:') || preg_match('/^v[1-9][0-9]*$/D', $version) !== 1) {
            throw new InvalidArgumentException('Select an Item Object ID and an explicit OCFL revision.');
        }
    }
}
