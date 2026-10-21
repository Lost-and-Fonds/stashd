<?php

namespace App\RecordsAndArchives;

use League\Uri\Exceptions\SyntaxError;
use League\Uri\Uri;
use League\Uri\Urn;
use Symfony\Component\Uid\Uuid;

/** Shared exact-uri-v1 identity policy; independent of archival writes and Asset inspection. */
final readonly class ItemIdentity
{
    public function __construct(
        private Vault $vault,
    ) {}

    public function objectIdForUri(string $uri): Urn
    {
        return $this->vault->urnFromUuid(IdentifierType::Item, $this->uuidForUri($uri));
    }

    public function uuidForUri(string $uri): Uuid
    {
        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:.+$/D', $uri) || preg_match('/[^a-zA-Z0-9._~:\/?#\[\]@!$&\x27()*+,;=%-]/', $uri) || preg_match('/%(?![a-fA-F0-9]{2})/', $uri)) {
            throw new InvalidItemRevision('Canonical Item identity must be an absolute, ASCII URI with valid percent escapes.');
        }
        try {
            Uri::new($uri);
        } catch (SyntaxError $exception) {
            throw new InvalidItemRevision('Canonical Item URI is invalid.', previous: $exception);
        }

        // Parse only for validation: never hash the parser's normalized serialization.
        return Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_URL), 'stashd:item:exact-uri-v1:' . $uri);
    }
}
